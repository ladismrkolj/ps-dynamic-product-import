<?php

namespace ProductImport\Service;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class ExpressionEvaluator
{
    private $language;

    public function __construct()
    {
        $this->language = new ExpressionLanguage();
        foreach (['path', 'num', 'str', 'regex', 'first', 'lower', 'upper', 'trim', 'replace', 'round', 'join', 'count', 'pairs', 'common', 'pick'] as $name) {
            $this->language->register(
                $name,
                static function (...$arguments) use ($name) {
                    return '\\' . self::class . '::' . $name . '(' . implode(', ', $arguments) . ')';
                },
                static function (array $variables, ...$arguments) use ($name) {
                    return self::$name(...$arguments);
                }
            );
        }
    }

    public function evaluate(string $expression, array $variables): mixed
    {
        // Feed items omit fields freely (no variants, fewer images): a missing key is null, not a warning.
        set_error_handler(static function (int $severity, string $message): bool {
            return str_starts_with($message, 'Undefined array key');
        }, E_WARNING);
        try {
            return $this->language->evaluate($expression, $variables);
        } catch (\Throwable $error) {
            throw new ExpressionEvaluationException($expression, $error);
        } finally {
            restore_error_handler();
        }
    }

    public static function path($data, string $path)
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    public static function num($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function str($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            throw new \InvalidArgumentException('str() expects a scalar or stringable value.');
        }

        return (string) $value;
    }

    public static function regex(string $pattern, $subject): bool
    {
        if ($subject === null) {
            return false;
        }
        $result = @preg_match($pattern, self::str($subject));
        if ($result === false) {
            throw new \InvalidArgumentException('Invalid regular expression (PCRE error ' . preg_last_error() . ').');
        }

        return $result === 1;
    }

    public static function first($list)
    {
        return is_array($list) && $list !== [] ? reset($list) : null;
    }

    public static function lower($value): ?string
    {
        $text = self::text('lower', $value);

        return $text === null ? null : mb_strtolower($text, 'UTF-8');
    }

    public static function upper($value): ?string
    {
        $text = self::text('upper', $value);

        return $text === null ? null : mb_strtoupper($text, 'UTF-8');
    }

    public static function trim($value): ?string
    {
        $text = self::text('trim', $value);
        if ($text === null) {
            return null;
        }
        $trimmed = preg_replace('/^[\pZ\s]+|[\pZ\s]+$/u', '', $text);
        if ($trimmed === null) {
            throw new \InvalidArgumentException('trim() expects valid UTF-8 text.');
        }

        return $trimmed;
    }

    public static function replace($value, $search, $with): ?string
    {
        $text = self::text('replace', $value);
        if ($text === null) {
            return null;
        }
        $from = self::text('replace', $search);
        $to = self::text('replace', $with);
        if ($from === null || $to === null) {
            throw new \InvalidArgumentException('replace() needs text for the search and replacement arguments.');
        }

        return str_replace($from, $to, $text);
    }

    public static function round($value, $decimals = 0): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('round() expects a number; wrap text in num() first.');
        }
        if (is_bool($decimals) || !is_numeric($decimals) || (float) $decimals != (int) $decimals) {
            throw new \InvalidArgumentException('round() expects a whole number of decimals.');
        }

        return round((float) $value, (int) $decimals, PHP_ROUND_HALF_UP);
    }

    public static function join($list, $separator = ', '): ?string
    {
        if ($list === null) {
            return null;
        }
        if (!is_array($list)) {
            throw new \InvalidArgumentException('join() expects a list.');
        }
        $separatorText = self::text('join', $separator);
        if ($separatorText === null) {
            throw new \InvalidArgumentException('join() expects text as the separator.');
        }
        $parts = [];
        foreach ($list as $entry) {
            if ($entry === null || $entry === '') {
                continue;
            }
            if (!is_scalar($entry)) {
                throw new \InvalidArgumentException('join() expects a list of text or numbers.');
            }
            $parts[] = (string) $entry;
        }

        return implode($separatorText, $parts);
    }

    public static function count($list): int
    {
        if ($list === null) {
            return 0;
        }
        if (!is_array($list)) {
            throw new \InvalidArgumentException('count() expects a list.');
        }

        return count($list);
    }

    /** Turns a list of objects into a {key: value} map; a single object counts as a one-entry list. */
    public static function pairs($list, $keyPath, $valuePath): ?array
    {
        if ($list === null) {
            return null;
        }
        if (!is_array($list)) {
            throw new \InvalidArgumentException('pairs() expects a list of objects.');
        }
        if (!is_string($keyPath) || !is_string($valuePath)) {
            throw new \InvalidArgumentException('pairs() expects text paths for the key and the value.');
        }
        $entries = array_is_list($list) ? $list : [$list];
        $map = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = self::path($entry, $keyPath);
            if (!is_scalar($key) || trim((string) $key) === '') {
                continue;
            }
            $map[trim((string) $key)] = self::path($entry, $valuePath);
        }

        return $map;
    }

    /**
     * Merges a list of {name: value} objects into one map, keeping only names whose non-empty scalar values
     * (compared as trimmed text) agree in every object; a single object counts as a one-entry list.
     */
    public static function common($list): array
    {
        if ($list === null) {
            return [];
        }
        if (!is_array($list)) {
            throw new \InvalidArgumentException('common() expects a list of objects.');
        }
        $merged = [];
        $conflicts = [];
        foreach (array_is_list($list) ? $list : [$list] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry as $name => $value) {
                if (!is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }
                $name = (string) $name;
                $text = trim((string) $value);
                if (!array_key_exists($name, $merged)) {
                    $merged[$name] = $text;
                } elseif ($merged[$name] !== $text) {
                    $conflicts[$name] = true;
                }
            }
        }

        return array_diff_key($merged, $conflicts);
    }

    /** Keeps the listed keys of a map, in the order of $names, renamed when $names is a {feed name: new name} map; the first present synonym wins. */
    public static function pick($map, $names): array
    {
        if ($map === null) {
            return [];
        }
        if (!is_array($map)) {
            throw new \InvalidArgumentException('pick() expects a map.');
        }
        if (!is_array($names)) {
            throw new \InvalidArgumentException('pick() expects a list of names or a {name: new name} map.');
        }
        $picked = [];
        foreach ($names as $from => $to) {
            if (array_is_list($names)) {
                $from = $to;
            }
            if (!is_scalar($from) || !is_scalar($to) || array_key_exists((string) $to, $picked)) {
                continue;
            }
            $value = $map[(string) $from] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $picked[(string) $to] = $value;
        }

        return $picked;
    }

    private static function text(string $function, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            throw new \InvalidArgumentException($function . '() expects a scalar or stringable value.');
        }

        return (string) $value;
    }
}
