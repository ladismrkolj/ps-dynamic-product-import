<?php

namespace ProductImport\Service;

/**
 * Scans every decoded feed item and builds the union of column paths.
 *
 * A column is identified by its parts: string keys, plus null for a list marker (so the marker
 * cannot be confused with a key named "0" or "*"). Lists become wildcard segments: images[*],
 * variants[*].size. A list column (trailing marker) describes the list itself; list_of_objects
 * lists have sub-columns below the marker.
 *
 * Decoded empty JSON objects and lists are both [] and are treated as empty lists.
 */
final class FeedSchema
{
    public const MAX_DEPTH = 6;
    public const MAX_COLUMNS = 1000;
    public const MAX_SAMPLES = 5;
    public const SAMPLE_LENGTH = 80;
    /** Distinct values are counted per top-level scalar column up to this many, bounding memory on huge feeds. */
    public const MAX_DISTINCT = 100000;

    private array $columns = [];
    private bool $truncated = false;

    /** @return array{columns: list<array<string, mixed>>, total: int, truncated: bool} */
    public function scan(array $items): array
    {
        $this->columns = [];
        $this->truncated = false;
        foreach (array_values($items) as $index => $item) {
            if (is_array($item) && ($item === [] || !array_is_list($item))) {
                $this->children($item, [], $index);
            }
        }
        $columns = [];
        foreach ($this->columns as $column) {
            $kind = $column['kind'];
            if ($kind === 'list') {
                $kind = $column['objects'] ? 'list_of_objects' : 'list_of_scalars';
            }
            $columns[] = [
                'key' => self::label($column['parts']),
                'parts' => $column['parts'],
                'kind' => $kind,
                'types' => array_keys($column['types']),
                'present' => $column['present'],
                'distinct' => count($column['parts']) === 1 && $column['kind'] === 'scalar' ? count($column['distinct']) : null,
                'total' => count($items),
                'samples' => $column['samples'],
                'max_length' => $column['kind'] === 'list' ? $column['max'] : null,
            ];
        }

        return ['columns' => $columns, 'total' => count($items), 'truncated' => $this->truncated];
    }

    /** Canonical label: dotted keys, [*] for lists, ["odd key"] for keys outside [A-Za-z_][A-Za-z0-9_]*. */
    public static function label(array $parts): string
    {
        $label = '';
        foreach ($parts as $index => $part) {
            if ($part === null) {
                $label .= '[*]';
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $part)) {
                $label .= ($index > 0 ? '.' : '') . $part;
            } else {
                $label .= '[' . json_encode((string) $part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ']';
            }
        }

        return $label;
    }

    private function children(array $object, array $parts, int $item): void
    {
        foreach ($object as $key => $child) {
            $this->visit($child, array_merge($parts, [(string) $key]), $item);
        }
    }

    private function visit(mixed $value, array $parts, int $item): void
    {
        if (count($parts) > self::MAX_DEPTH) {
            $this->truncated = true;

            return;
        }
        if (is_array($value) && ($value === [] || array_is_list($value))) {
            $this->visitList($value, $parts, $item);

            return;
        }
        if (is_array($value)) {
            $column = &$this->column($parts, 'object');
            if ($column !== null) {
                $this->touch($column, $item, 'object');
            }
            unset($column);
            $this->children($value, $parts, $item);

            return;
        }
        $column = &$this->column($parts, 'scalar');
        if ($column !== null) {
            $present = $value !== null && $value !== '';
            $this->touch($column, $item, self::type($value), $present);
            if ($present) {
                $this->sample($column, $value);
                if (count($parts) === 1) {
                    $this->distinct($column, $value);
                }
            }
        }
    }

    private function visitList(array $list, array $parts, int $item): void
    {
        $listParts = array_merge($parts, [null]);
        if (count($listParts) > self::MAX_DEPTH) {
            $this->truncated = true;

            return;
        }
        $column = &$this->column($listParts, 'list');
        if ($column === null) {
            return;
        }
        $this->touch($column, $item, null, $list !== []);
        $column['max'] = max($column['max'], count($list));
        unset($column);
        foreach ($list as $element) {
            if (is_array($element) && $element !== [] && !array_is_list($element)) {
                $column = &$this->column($listParts, 'list');
                $column['objects'] = true;
                $column['types']['object'] = true;
                unset($column);
                $this->children($element, $listParts, $item);
            } elseif (is_array($element)) {
                $this->visit($element, $listParts, $item);
            } else {
                $column = &$this->column($listParts, 'list');
                $column['types'][self::type($element)] = true;
                if ($element !== null && $element !== '') {
                    $this->sample($column, $element);
                }
                unset($column);
            }
        }
    }

    /** @return array<string, mixed>|null reference to the column record, or null when the column cap is reached */
    private function &column(array $parts, string $kind): ?array
    {
        $id = json_encode($parts);
        if (!isset($this->columns[$id])) {
            if (count($this->columns) >= self::MAX_COLUMNS) {
                $this->truncated = true;
                $none = null;

                return $none;
            }
            $this->columns[$id] = ['parts' => $parts, 'kind' => $kind, 'objects' => false, 'types' => [], 'present' => 0,
                'samples' => [], 'max' => 0, 'last' => -1, 'distinct' => []];
        } elseif ($kind === 'object' && $this->columns[$id]['kind'] === 'scalar') {
            $this->columns[$id]['kind'] = 'object';
        }

        return $this->columns[$id];
    }

    private function touch(array &$column, int $item, ?string $type, bool $present = true): void
    {
        if ($type !== null) {
            $column['types'][$type] = true;
        }
        if ($present && $column['last'] !== $item) {
            $column['last'] = $item;
            ++$column['present'];
        }
    }

    private function sample(array &$column, mixed $value): void
    {
        if (count($column['samples']) >= self::MAX_SAMPLES) {
            return;
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $text = mb_substr($text, 0, self::SAMPLE_LENGTH);
        if (!in_array($text, $column['samples'], true)) {
            $column['samples'][] = $text;
        }
    }

    /** Counts distinct non-empty values by their string form (1 and "1" are the same identifier); stops at MAX_DISTINCT. */
    private function distinct(array &$column, mixed $value): void
    {
        if (count($column['distinct']) >= self::MAX_DISTINCT) {
            return;
        }
        $column['distinct'][is_bool($value) ? ($value ? 'true' : 'false') : (string) $value] = true;
    }

    private static function type(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            default => 'string',
        };
    }
}
