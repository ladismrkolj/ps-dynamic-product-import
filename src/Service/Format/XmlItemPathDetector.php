<?php

namespace ProductImport\Service\Format;

/**
 * Suggests the xml_item_path of an XML feed by streaming it with XMLReader (no DOM).
 *
 * Element names are compared by local name, so the namespace and prefix are ignored: <ns:item> and <item> count as the
 * same path, and the suggested path is always usable as xml_item_path (the parser also matches local names). The root
 * element's own name never appears in a path.
 *
 * Candidates are elements that occur at least twice at the same path and have element children. Ranking: candidates that
 * sit inside another candidate and repeat per parent no more often than that ancestor occurs (images/image inside each
 * product) are nested lists and come after the rest; within each group, higher count first, then shallower path. When no
 * path repeats, the root's direct child elements that have children are returned with count 1.
 */
class XmlItemPathDetector
{
    public const MAX_ELEMENTS = 200000;
    public const MAX_FIELDS = 6;

    /**
     * @return list<array{path: string, count: int, fields: list<string>, approximate: bool}>
     */
    public static function detect(string $body): array
    {
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            if (trim($body) === '' || !$reader->XML($body, null, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new \RuntimeException('Invalid XML.');
            }
            [$stats, $approximate] = self::scan($reader);
            if ($approximate === null) {
                $errors = array_map(static fn ($error) => trim($error->message), libxml_get_errors());
                throw new \RuntimeException('Invalid XML: ' . implode('; ', array_unique($errors)));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return self::rank($stats, $approximate);
    }

    /**
     * @return array{0: array<string, array{count: int, perParent: int, fields: list<string>, depth: int}>, 1: ?bool} second
     *                                                                                                                  value is null when the XML is malformed
     */
    private static function scan(\XMLReader $reader): array
    {
        $stats = [];
        $names = [];
        $siblings = [];
        $collect = [];
        $elements = 0;
        $sawRoot = false;
        while (true) {
            $more = $reader->read();
            if (!$more) {
                return [$stats, $sawRoot && array_filter(libxml_get_errors(), static fn ($e) => $e->level >= LIBXML_ERR_ERROR) === [] ? false : null];
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            $depth = $reader->depth;
            $sawRoot = true;
            if ($depth === 0) {
                continue;
            }
            if (++$elements > self::MAX_ELEMENTS) {
                return [$stats, true];
            }
            $name = $reader->localName;
            $names = array_slice($names, 0, $depth - 1);
            $names[] = $name;
            $path = implode('/', $names);
            $siblings[$depth] ??= [];
            $siblings[$depth + 1] = [];
            $siblings[$depth][$name] = ($siblings[$depth][$name] ?? 0) + 1;
            $stats[$path] ??= ['count' => 0, 'perParent' => 0, 'fields' => [], 'depth' => $depth];
            $stats[$path]['count']++;
            $stats[$path]['perParent'] = max($stats[$path]['perParent'], $siblings[$depth][$name]);
            // Field names come from the first occurrence that has element children.
            $collect[$depth] = $stats[$path]['fields'] === [] ? $path : null;
            $parent = $collect[$depth - 1] ?? null;
            if ($parent !== null && count($stats[$parent]['fields']) < self::MAX_FIELDS && !in_array($name, $stats[$parent]['fields'], true)) {
                $stats[$parent]['fields'][] = $name;
            }
        }
    }

    private static function rank(array $stats, bool $approximate): array
    {
        $candidates = [];
        foreach ($stats as $path => $stat) {
            if ($stat['count'] >= 2 && $stat['fields'] !== []) {
                $candidates[$path] = $stat;
            }
        }
        if ($candidates === []) {
            foreach ($stats as $path => $stat) {
                if ($stat['depth'] === 1 && $stat['fields'] !== []) {
                    $candidates[$path] = $stat;
                }
            }
        }
        $nested = [];
        foreach ($candidates as $path => $stat) {
            $nested[$path] = false;
            for ($ancestor = $path; ($cut = strrpos($ancestor, '/')) !== false;) {
                $ancestor = substr($ancestor, 0, $cut);
                if (isset($candidates[$ancestor]) && $candidates[$ancestor]['count'] >= $stat['perParent']) {
                    $nested[$path] = true;
                    break;
                }
            }
        }
        uksort($candidates, static fn ($a, $b) => [$nested[$a], -$candidates[$a]['count'], $candidates[$a]['depth'], $a]
            <=> [$nested[$b], -$candidates[$b]['count'], $candidates[$b]['depth'], $b]);
        $result = [];
        foreach ($candidates as $path => $stat) {
            $result[] = ['path' => (string) $path, 'count' => $stat['count'], 'fields' => $stat['fields'], 'approximate' => $approximate];
        }

        return $result;
    }
}
