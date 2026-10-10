<?php

namespace ProductImport\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Portable source configuration (YAML): builds the export document, validates an uploaded one (YAML or the
 * older JSON exports) and resolves its names to the IDs of the importing store. Pure logic; every store lookup is injected.
 */
class SourceConfigTransfer
{
    public const FORMAT = 'productimport-source';
    public const VERSION = 1;
    public const MAX_BYTES = 1048576;
    public const IMPORTED_SUFFIX = ' (imported)';
    private const MAX_WARNINGS = 100;
    private const MAX_MAPPINGS = 20000;
    private const MAX_DEPTH = 32;

    /**
     * @param array $lookups language_iso(int):?string, category_path(int):?list<string>,
     *                       manufacturer_name(int):?string, supplier_name(int):?string
     */
    public function build(array $source, array $categoryMappings, array $brandMappings, array $lookups, string $moduleVersion, string $exportedAt): array
    {
        $fieldMapping = self::decodeObject((string) ($source['field_mapping'] ?? ''), 'field_mapping');
        $variant = trim((string) ($source['variant_mapping'] ?? '')) === '' ? null : self::decodeObject((string) $source['variant_mapping'], 'variant_mapping');
        $id = static function ($value, callable $lookup) {
            return $value === null || $value === '' ? null : $lookup((int) $value);
        };
        $path = static function (?array $segments) {
            return $segments === null ? null : self::pathValue($segments);
        };
        $categories = [];
        foreach ($categoryMappings as $row) {
            $feedPath = json_decode((string) $row['source_path'], true);
            if (!is_array($feedPath) || $feedPath === []) {
                continue;
            }
            $categories[] = ['feed' => self::pathValue(array_values($feedPath)), 'category' => $path($id($row['id_category'], $lookups['category_path']))];
        }
        $brands = [];
        foreach ($brandMappings as $row) {
            $brands[] = ['feed' => (string) $row['source_name'], 'brand' => $id($row['id_manufacturer'], $lookups['manufacturer_name'])];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => $exportedAt,
            'module_version' => $moduleVersion,
            'source' => [
                'name' => (string) $source['name'],
                'technical_key' => (string) $source['technical_key'],
                'language' => $lookups['language_iso']((int) $source['id_lang_default']),
                'active' => (bool) $source['active'],
                'deactivate_missing' => (bool) $source['deactivate_missing'],
                'source_format' => (string) $source['source_format'],
                'json_url' => $source['json_url'] ?? null,
                'json_file_path' => $source['json_file_path'] ?? null,
                'xml_item_path' => $source['xml_item_path'] ?? null,
                'identifier_field' => (string) $source['identifier_field'],
                'filter_expression' => $source['filter_expression'] ?? null,
                'root_category' => $path($id($source['root_category_id'] ?? null, $lookups['category_path'])),
                'default_category' => $path($id($source['default_id_category'] ?? null, $lookups['category_path'])),
                'default_manufacturer' => $id($source['default_id_manufacturer'] ?? null, $lookups['manufacturer_name']),
                'supplier' => $id($source['id_supplier'] ?? null, $lookups['supplier_name']),
                'field_mapping' => $fieldMapping,
                'variant_mapping' => $variant,
            ],
            'category_mappings' => $categories,
            'brand_mappings' => $brands,
        ];
    }

    /** The export as human-readable YAML: a comment header, block style, multi-line expressions as literal blocks. */
    public static function encode(array $document): string
    {
        $header = "# Product Import source configuration (format " . self::FORMAT . ", version " . self::VERSION . ").\n"
            . "# Edit this file and upload it again in Product Import > Import source: either as a new source,\n"
            . "# or to overwrite an existing one. On overwrite, entries in this file replace the source's values and\n"
            . "# anything missing from the file is kept as it is. technical_key is never changed on overwrite.\n"
            . "#\n"
            . "# field_mapping:      PrestaShop field: feed expression.\n"
            . "# category_mappings:  feed: category path in the feed; category: PrestaShop category path below the shop root\n"
            . "#                     (names joined with ' > ', matched exactly). null = create the category automatically.\n"
            . "# brand_mappings:     feed: brand name in the feed; brand: PrestaShop brand name. null = create it automatically.\n"
            . "# Mapping entries are matched by their feed value: listed entries are added or updated, others are kept.\n\n";
        try {
            $yaml = Yaml::dump($document, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            // Put the first key of a list item on the dash line ("- feed: x"); keep the original if that would change the data.
            $compact = preg_replace('/^( *)-\n\1  (?=\S)/m', '$1- ', $yaml);
            if ($compact !== null && Yaml::parse($compact) === Yaml::parse($yaml)) {
                $yaml = $compact;
            }

            return $header . $yaml;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to encode source export: ' . $error->getMessage());
        }
    }

    /**
     * Strictly validates an uploaded document (YAML, or the older JSON exports) and returns its normalised form.
     *
     * @param bool $partial overwrite mode: every source setting is optional and only the present ones are returned
     *
     * @throws \InvalidArgumentException with a message safe to show to the admin (still escape it)
     */
    public function parse(string $text, bool $partial = false): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The file is larger than ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (preg_match('/(?:[\[{]\s*){' . (self::MAX_DEPTH + 1) . '}/', $text) === 1) {
            throw new \InvalidArgumentException('The file is nested too deeply.');
        }
        try {
            $document = Yaml::parse($text);
        } catch (ParseException $error) {
            throw new \InvalidArgumentException('The file is not valid YAML: ' . $error->getMessage());
        }
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            throw new \InvalidArgumentException('The file must contain a YAML (or JSON) mapping with "format", "version" and "source".');
        }
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('This is not a Product Import source export (format must be "' . self::FORMAT . '").');
        }
        $version = $document['version'] ?? null;
        if (!is_int($version) || $version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported export version ' . (is_scalar($version) ? json_encode($version) : 'of unexpected type') . '; this module reads version ' . self::VERSION . '.');
        }
        if ($partial && !array_key_exists('source', $document)) {
            $in = [];
        } elseif (!isset($document['source']) || !is_array($document['source']) || array_is_list($document['source']) && $document['source'] !== []) {
            throw new \InvalidArgumentException('"source" must be a mapping.');
        } else {
            $in = $document['source'];
        }
        $source = self::parseSource($in, $partial);

        $categories = [];
        $seen = [];
        $rows = $document['category_mappings'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_MAPPINGS) {
            throw new \InvalidArgumentException('"category_mappings" must be a list of at most ' . self::MAX_MAPPINGS . ' entries.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Every category mapping must be a mapping with "feed" and "category".');
            }
            $path = self::optionalPath($row['feed'] ?? $row['source_path'] ?? null, 'category_mappings[].feed');
            if ($path === null) {
                throw new \InvalidArgumentException('Every category mapping needs a "feed" category path.');
            }
            $hash = sha1(json_encode($path));
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $categories[] = ['source_path' => $path, 'category' => self::optionalPath($row['category'] ?? null, 'category_mappings[].category')];
            }
        }
        $brands = [];
        $seen = [];
        $rows = $document['brand_mappings'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_MAPPINGS) {
            throw new \InvalidArgumentException('"brand_mappings" must be a list of at most ' . self::MAX_MAPPINGS . ' entries.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Every brand mapping must be a mapping with "feed" and "brand".');
            }
            $name = $row['feed'] ?? $row['source_name'] ?? null;
            if (!is_string($name) || $name === '' || mb_strlen($name) > 191) {
                throw new \InvalidArgumentException('Every brand mapping needs a "feed" brand name of 1 to 191 characters.');
            }
            $manufacturer = self::optionalString($row, array_key_exists('brand', $row) ? 'brand' : 'manufacturer');
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $brands[] = ['source_name' => $name, 'manufacturer' => $manufacturer];
            }
        }

        return ['source' => $source, 'category_mappings' => $categories, 'brand_mappings' => $brands];
    }

    /**
     * Resolves names to this store's IDs for a NEW source. Unmatched names stay unset and become warnings.
     *
     * @param array $lookups language_id(string $iso):?int, default_language_id():int, root_category_id:int,
     *                       category_children(int $parent, int $idLang):array<int,string> (id => name),
     *                       manufacturers(string $name):array<int,string>, suppliers(string $name):array<int,string>
     * @param callable $keyTaken fn(string $key):bool
     *
     * @return array{source: array, category_mappings: list<array>, brand_mappings: list<array>, warnings: list<string>}
     */
    public function resolve(array $document, array $lookups, callable $keyTaken): array
    {
        $in = $document['source'];
        $warnings = [];
        $data = [
            'name' => $in['name'], 'technical_key' => $in['technical_key'], 'json_url' => $in['json_url'],
            'json_file_path' => $in['json_file_path'], 'source_format' => $in['source_format'], 'xml_item_path' => $in['xml_item_path'],
            'identifier_field' => $in['identifier_field'], 'filter_expression' => $in['filter_expression'],
            'field_mapping' => $in['field_mapping'], 'variant_mapping' => $in['variant_mapping'],
            'active' => $in['active'] ? 1 : 0, 'deactivate_missing' => $in['deactivate_missing'] ? 1 : 0,
        ];
        [$data['technical_key'], $changed] = self::uniqueKey($in['technical_key'], $keyTaken);
        if ($changed) {
            $data['name'] = mb_substr($in['name'], 0, 191 - mb_strlen(self::IMPORTED_SUFFIX)) . self::IMPORTED_SUFFIX;
            $warnings[] = 'Technical key "' . $in['technical_key'] . '" is already used; the source was imported as "' . $data['technical_key'] . '".';
        }

        $idLang = $lookups['language_id']($in['language']);
        if ($idLang === null) {
            $idLang = (int) $lookups['default_language_id']();
            $warnings[] = 'Language "' . $in['language'] . '" is not installed here; the shop default language was used.';
        }
        $data['id_lang_default'] = $idLang;

        $data['root_category_id'] = self::categoryId($in['root_category'], 'Root category', $lookups, $idLang, $warnings);
        $data['default_id_category'] = self::categoryId($in['default_category'], 'Default category', $lookups, $idLang, $warnings);
        $data['default_id_manufacturer'] = self::namedId($in['default_manufacturer'], $lookups['manufacturers'], 'Default brand', $warnings);
        $data['id_supplier'] = self::namedId($in['supplier'], $lookups['suppliers'], 'Supplier', $warnings);

        $categories = [];
        foreach ($document['category_mappings'] as $row) {
            $id = self::categoryId($row['category'], 'Category mapping for "' . self::joinPath($row['source_path']) . '": category', $lookups, $idLang, $warnings);
            $categories[] = ['source_path' => $row['source_path'], 'id_category' => $id];
        }
        $brands = [];
        foreach ($document['brand_mappings'] as $row) {
            $brands[] = ['source_name' => $row['source_name'],
                'id_manufacturer' => self::namedId($row['manufacturer'], $lookups['manufacturers'], 'Brand mapping for "' . $row['source_name'] . '": brand', $warnings)];
        }

        return ['source' => $data, 'category_mappings' => $categories, 'brand_mappings' => $brands, 'warnings' => self::capWarnings($warnings)];
    }

    /**
     * Plans the overwrite of an existing source: only what the file lists changes, everything else stays.
     * Settings and mapping entries whose names do not match this store are left as they were and reported.
     * An explicit null (root/default category, brand, supplier, mapping target) resets the value. technical_key is never changed.
     *
     * @param array $document result of parse($text, true)
     * @param array $existing the target's pi_source row
     * @param array $lookups see resolve(), without the key and default-language entries
     *
     * @throws \InvalidArgumentException when the resulting source would be invalid
     *
     * @return array{source: array, category_mappings: list<array>, brand_mappings: list<array>, warnings: list<string>}
     *               source holds only the changed pi_source columns; mapping rows are the entries to upsert (id_category / id_manufacturer null = auto-create)
     */
    public function resolveOverwrite(array $document, array $existing, array $lookups): array
    {
        $in = $document['source'];
        $warnings = [];
        $changes = [];
        foreach (['name', 'json_url', 'json_file_path', 'source_format', 'xml_item_path', 'identifier_field', 'filter_expression', 'field_mapping', 'variant_mapping'] as $key) {
            if (array_key_exists($key, $in)) {
                $changes[$key] = $in[$key];
            }
        }
        foreach (['active', 'deactivate_missing'] as $key) {
            if (array_key_exists($key, $in)) {
                $changes[$key] = $in[$key] ? 1 : 0;
            }
        }
        $idLang = (int) $existing['id_lang_default'];
        if (array_key_exists('language', $in)) {
            $found = $lookups['language_id']($in['language']);
            if ($found === null) {
                $warnings[] = 'Language "' . $in['language'] . '" is not installed here; the source language was left unchanged.';
            } else {
                $idLang = $changes['id_lang_default'] = (int) $found;
            }
        }
        foreach (['root_category' => ['root_category_id', 'Root category'], 'default_category' => ['default_id_category', 'Default category']] as $key => [$column, $label]) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            if ($in[$key] === null) {
                $changes[$column] = null;
            } elseif (($id = self::categoryId($in[$key], $label, $lookups, $idLang, $warnings)) !== null) {
                $changes[$column] = $id;
            }
        }
        foreach (['default_manufacturer' => ['default_id_manufacturer', 'manufacturers', 'Default brand'], 'supplier' => ['id_supplier', 'suppliers', 'Supplier']] as $key => [$column, $lookup, $label]) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            if ($in[$key] === null) {
                $changes[$column] = null;
            } elseif (($id = self::namedId($in[$key], $lookups[$lookup], $label, $warnings)) !== null) {
                $changes[$column] = $id;
            }
        }
        $merged = self::checkSource($changes + $existing);
        if (array_key_exists('source_format', $changes) || array_key_exists('xml_item_path', $changes)) {
            $changes['xml_item_path'] = $merged['xml_item_path'];
        }

        $categories = [];
        foreach ($document['category_mappings'] as $row) {
            $label = 'Category mapping for "' . self::joinPath($row['source_path']) . '": category';
            if ($row['category'] === null) {
                $categories[] = ['source_path' => $row['source_path'], 'id_category' => null];
            } elseif (($id = self::categoryId($row['category'], $label, $lookups, $idLang, $warnings)) !== null) {
                $categories[] = ['source_path' => $row['source_path'], 'id_category' => $id];
            }
        }
        $brands = [];
        foreach ($document['brand_mappings'] as $row) {
            if ($row['manufacturer'] === null) {
                $brands[] = ['source_name' => $row['source_name'], 'id_manufacturer' => null];
            } elseif (($id = self::namedId($row['manufacturer'], $lookups['manufacturers'], 'Brand mapping for "' . $row['source_name'] . '": brand', $warnings)) !== null) {
                $brands[] = ['source_name' => $row['source_name'], 'id_manufacturer' => $id];
            }
        }

        return ['source' => $changes, 'category_mappings' => $categories, 'brand_mappings' => $brands, 'warnings' => self::capWarnings($warnings)];
    }

    private static function categoryId(?array $path, string $label, array $lookups, int $idLang, array &$warnings): ?int
    {
        if ($path === null) {
            return null;
        }
        [$id, $problem] = self::matchCategoryPath($path, (int) $lookups['root_category_id'], $idLang, $lookups['category_children']);
        if ($id === null) {
            $warnings[] = $label . ' "' . self::joinPath($path) . '" ' . $problem . '.';
        }

        return $id;
    }

    private static function namedId(?string $value, callable $lookup, string $label, array &$warnings): ?int
    {
        if ($value === null) {
            return null;
        }
        [$id, $problem] = self::matchName($value, $lookup($value));
        if ($id === null) {
            $warnings[] = $label . ' "' . $value . '" ' . $problem . '.';
        }

        return $id;
    }

    private static function capWarnings(array $warnings): array
    {
        if (count($warnings) > self::MAX_WARNINGS) {
            $extra = count($warnings) - self::MAX_WARNINGS;
            $warnings = array_slice($warnings, 0, self::MAX_WARNINGS);
            $warnings[] = '... and ' . $extra . ' more unmatched names.';
        }

        return $warnings;
    }

    /** @return list<string> the path from a human string: segments separated by " > " (surrounding spaces of a name are kept) */
    public static function splitPath(string $path): array
    {
        return explode(' > ', $path);
    }

    public static function joinPath(array $segments): string
    {
        return implode(' > ', $segments);
    }

    /** The export form of a path: a " > " string, or the list itself when a name contains " > " and a string would not split back. */
    private static function pathValue(array $segments)
    {
        foreach ($segments as $segment) {
            if (str_contains((string) $segment, ' > ')) {
                return array_values($segments);
            }
        }

        return self::joinPath($segments);
    }

    /** Cross-field rules of a complete source (also applied to the merge result of an overwrite). */
    private static function checkSource(array $source): array
    {
        if (!in_array($source['source_format'] ?? null, ['json', 'xml'], true)) {
            throw new \InvalidArgumentException('"source.source_format" must be "json" or "xml".');
        }
        if ($source['source_format'] === 'xml' && trim((string) ($source['xml_item_path'] ?? '')) === '') {
            throw new \InvalidArgumentException('XML item path is required for XML sources.');
        }
        if ($source['source_format'] === 'json') {
            $source['xml_item_path'] = null;
        }
        self::validateSourceFields($source);

        return $source;
    }

    private static function parseSource(array $in, bool $partial): array
    {
        $source = [];
        foreach (['name', 'technical_key', 'source_format', 'identifier_field', 'language'] as $key) {
            if (!$partial || array_key_exists($key, $in)) {
                $source[$key] = self::requireString($in, $key);
            }
        }
        foreach (['json_url', 'json_file_path', 'xml_item_path', 'filter_expression', 'default_manufacturer', 'supplier'] as $key) {
            if (!$partial || array_key_exists($key, $in)) {
                $source[$key] = self::optionalString($in, $key);
            }
        }
        foreach (['active', 'deactivate_missing'] as $key) {
            if ($partial && !array_key_exists($key, $in)) {
                continue;
            }
            if (!isset($in[$key]) || !is_bool($in[$key])) {
                throw new \InvalidArgumentException('"source.' . $key . '" must be true or false.');
            }
            $source[$key] = $in[$key];
        }
        foreach (['name', 'technical_key', 'identifier_field', 'json_url', 'json_file_path', 'xml_item_path', 'filter_expression'] as $key) {
            if (isset($source[$key])) {
                $source[$key] = trim($source[$key]);
            }
        }
        foreach (['json_url', 'json_file_path', 'filter_expression'] as $key) {
            if (($source[$key] ?? null) === '') {
                $source[$key] = null;
            }
        }
        if (!$partial) {
            $source = self::checkSource($source);
        } else {
            if (isset($source['source_format']) && !in_array($source['source_format'], ['json', 'xml'], true)) {
                throw new \InvalidArgumentException('"source.source_format" must be "json" or "xml".');
            }
            foreach (['name' => 191, 'technical_key' => 64] as $key => $limit) {
                if (isset($source[$key]) && ($source[$key] === '' || mb_strlen($source[$key]) > $limit)) {
                    throw new \InvalidArgumentException($key . ' is required and must not exceed ' . $limit . ' characters.');
                }
            }
        }
        if (!$partial || array_key_exists('field_mapping', $in)) {
            $source['field_mapping'] = self::validateMapping($in['field_mapping'] ?? null, 'source.field_mapping');
        }
        if (!$partial || array_key_exists('variant_mapping', $in)) {
            $source['variant_mapping'] = self::validateVariantMapping($in['variant_mapping'] ?? null);
        }
        foreach (['root_category', 'default_category'] as $key) {
            if (!$partial || array_key_exists($key, $in)) {
                $source[$key] = self::optionalPath($in[$key] ?? null, 'source.' . $key);
            }
        }

        return $source;
    }

    /** @return array{0: string, 1: bool} the free key and whether it differs from the requested one */
    public static function uniqueKey(string $key, callable $taken): array
    {
        if (!$taken($key)) {
            return [$key, false];
        }
        for ($n = 2; $n < 10000; ++$n) {
            $suffix = '_' . $n;
            $candidate = rtrim(substr($key, 0, 64 - strlen($suffix)), '_-') . $suffix;
            if (!$taken($candidate)) {
                return [$candidate, true];
            }
        }
        throw new \RuntimeException('Unable to find a free technical key for "' . $key . '".');
    }

    /**
     * Walks the name path from the shop root; every segment must match exactly one child (exact, case-sensitive).
     *
     * @param callable $children fn(int $parent, int $idLang):array<int,string> id => name
     *
     * @return array{0: ?int, 1: string} id or null plus the reason for no match
     */
    public static function matchCategoryPath(array $path, int $rootId, int $idLang, callable $children): array
    {
        $parent = $rootId;
        foreach ($path as $segment) {
            [$id, $problem] = self::matchName($segment, $children($parent, $idLang));
            if ($id === null) {
                return [null, $problem === 'was not found' ? 'was not found here (stopped at "' . $segment . '")' : 'is ambiguous here (several "' . $segment . '")'];
            }
            $parent = $id;
        }

        return [$parent === $rootId ? null : $parent, $parent === $rootId ? 'is empty' : ''];
    }

    /** @return array{0: ?int, 1: string} the single candidate whose name equals $name exactly */
    public static function matchName(string $name, array $candidates): array
    {
        $ids = [];
        foreach ($candidates as $id => $candidate) {
            if ($candidate === $name) {
                $ids[] = (int) $id;
            }
        }
        if (count($ids) === 1) {
            return [$ids[0], ''];
        }

        return [null, $ids === [] ? 'was not found' : 'matches several entries'];
    }

    /** Field rules shared with the source form (readSubmission). */
    public static function validateSourceFields(array $data): void
    {
        foreach (['name' => 191, 'technical_key' => 64] as $field => $limit) {
            $value = (string) ($data[$field] ?? '');
            if ($value === '' || mb_strlen($value) > $limit) {
                throw new \InvalidArgumentException($field . ' is required and must not exceed ' . $limit . ' characters.');
            }
        }
        if (mb_strlen((string) ($data['identifier_field'] ?? '')) > 191) {
            throw new \InvalidArgumentException('identifier_field must not exceed 191 characters.');
        }
        if (!preg_match('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/D', (string) $data['technical_key'])) {
            throw new \InvalidArgumentException('Technical key must be a lowercase slug (letters, numbers, underscores or hyphens).');
        }
        $url = (string) ($data['json_url'] ?? '');
        if ($url === '' && (string) ($data['json_file_path'] ?? '') === '') {
            throw new \InvalidArgumentException('Provide a JSON URL or file path.');
        }
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new \InvalidArgumentException('JSON URL must be a valid HTTP or HTTPS URL.');
        }
    }

    private static function requireString(array $in, string $key): string
    {
        if (!isset($in[$key]) || !is_string($in[$key])) {
            throw new \InvalidArgumentException('"source.' . $key . '" must be a string.');
        }

        return $in[$key];
    }

    private static function optionalString(array $in, string $key): ?string
    {
        if (!array_key_exists($key, $in) || $in[$key] === null) {
            return null;
        }
        if (!is_string($in[$key])) {
            throw new \InvalidArgumentException('"' . $key . '" must be a string or null.');
        }

        return $in[$key] === '' ? null : $in[$key];
    }

    private static function optionalPath($value, string $label): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && $value !== '') {
            $value = self::splitPath($value);
        }
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new \InvalidArgumentException('"' . $label . '" must be a category path like "Home > Sport", a non-empty list of names, or null.');
        }
        foreach ($value as $segment) {
            if (!is_string($segment) || trim($segment) === '' || mb_strlen($segment) > 191) {
                throw new \InvalidArgumentException('"' . $label . '" must contain only non-empty names.');
            }
        }

        return $value;
    }

    /** @return string the mapping as the JSON object stored in pi_source.field_mapping */
    private static function validateMapping($mapping, string $label): string
    {
        if (!is_array($mapping) || ($mapping !== [] && array_is_list($mapping))) {
            throw new \InvalidArgumentException('"' . $label . '" must be an object of target => expression strings.');
        }
        $clean = [];
        foreach ($mapping as $target => $expression) {
            $target = trim((string) $target);
            if ($target === '' || !is_string($expression) || trim($expression) === '') {
                throw new \InvalidArgumentException('"' . $label . '" needs a non-empty target and a non-empty string expression in every entry.');
            }
            $clean[$target] = trim($expression);
        }

        return self::encodeObject($clean);
    }

    private static function validateVariantMapping($variant): ?string
    {
        if ($variant === null) {
            return null;
        }
        if (!is_array($variant) || !is_string($variant['variants_expression'] ?? null) || trim($variant['variants_expression']) === '') {
            throw new \InvalidArgumentException('"source.variant_mapping.variants_expression" must be a non-empty string.');
        }
        $attributes = $variant['attributes'] ?? null;
        if (!is_array($attributes) || $attributes === [] || !array_is_list($attributes)) {
            throw new \InvalidArgumentException('"source.variant_mapping.attributes" must be a non-empty list.');
        }
        $definitions = [];
        $names = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || !is_string($attribute['name'] ?? null) || trim($attribute['name']) === ''
                || !is_string($attribute['expression'] ?? null) || trim($attribute['expression']) === '' || isset($names[trim($attribute['name'])])) {
                throw new \InvalidArgumentException('Every variant attribute needs a unique name and an expression.');
            }
            $names[trim($attribute['name'])] = true;
            $definitions[] = ['name' => trim($attribute['name']), 'expression' => trim($attribute['expression'])];
        }
        $fields = json_decode(self::validateMapping($variant['fields'] ?? null, 'source.variant_mapping.fields'), true);
        if (!isset($fields['reference'])) {
            throw new \InvalidArgumentException('Variants require at least one attribute and a reference field mapping.');
        }
        $json = json_encode(['variants_expression' => trim($variant['variants_expression']), 'attributes' => $definitions, 'fields' => (object) $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Unable to encode variant mapping: ' . json_last_error_msg());
        }

        return $json;
    }

    private static function encodeObject(array $values): string
    {
        $json = json_encode((object) $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Unable to encode mapping: ' . json_last_error_msg());
        }

        return $json;
    }

    private static function decodeObject(string $json, string $label): array
    {
        $value = json_decode($json, true);
        if (!is_array($value)) {
            throw new \RuntimeException('Saved ' . $label . ' is not a valid JSON object.');
        }

        return $value;
    }
}
