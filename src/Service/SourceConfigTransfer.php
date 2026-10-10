<?php

namespace ProductImport\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Portable source configuration (YAML): builds the export document, validates an uploaded one and resolves its names to the IDs of the importing store. Pure logic; every store lookup is injected.
 */
class SourceConfigTransfer
{
    public const FORMAT = 'productimport-source';
    public const VERSION = 1;
    public const MAX_BYTES = 1048576;
    public const IMPORTED_SUFFIX = ' (imported)';
    /** Plain source columns copied verbatim from the file (new source and overwrite share these lists). technical_key is never overwritten. */
    private const TEXT_SETTINGS = ['name', 'technical_key', 'location', 'source_format', 'xml_item_path', 'identifier_field', 'filter_expression', 'field_mapping', 'variant_mapping'];
    private const FLAG_SETTINGS = ['active', 'deactivate_missing'];
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
            return $segments === null ? null : self::pathKey($segments);
        };
        // Maps "feed value: destination" (destination null = auto-create). Feed values are unique per source, so keys never collide.
        $categories = [];
        foreach ($categoryMappings as $row) {
            $feedPath = json_decode((string) $row['source_path'], true);
            if (!is_array($feedPath) || $feedPath === []) {
                continue;
            }
            $categories[self::pathKey(array_values($feedPath))] = $path($id($row['id_category'], $lookups['category_path']));
        }
        $brands = [];
        foreach ($brandMappings as $row) {
            $brands[(string) $row['source_name']] = $id($row['id_manufacturer'], $lookups['manufacturer_name']);
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
                'location' => $source['location'] ?? null,
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
            . "# location:           where the feed comes from: an http:// or https:// URL, or a file path inside the import folder\n"
            . "#                     (relative paths are resolved inside it). source_format selects how the feed is read.\n"
            . "# field_mapping:      PrestaShop field: feed expression.\n"
            . "# category_mappings:  feed category path: PrestaShop category path below the shop root (names joined with ' > ',\n"
            . "#                     matched exactly). ~ or null = create the category automatically. A name that itself\n"
            . "#                     contains ' > ' cannot be written here.\n"
            . "# brand_mappings:     feed brand name: PrestaShop brand name. ~ or null = create it automatically.\n"
            . "# Mapping entries are matched by their feed value and each feed value may appear once. Listed entries are\n"
            . "# added or updated, others are kept. An entry for a feed value not seen yet is stored and used once the feed\n"
            . "# contains it. If the PrestaShop destination does not exist, the entry is skipped with a warning.\n\n";
        try {
            $yaml = Yaml::dump($document, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            // Put the first key of a list item on the dash line ("- name: x"); keep the original if that would change the data.
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
     * Strictly validates an uploaded document (YAML) and returns its normalised form.
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
            $message = $error->getMessage();
            throw new \InvalidArgumentException('The file is not valid YAML: ' . (str_contains($message, 'Duplicate key')
                ? 'a key appears twice in the same mapping (each feed category or brand may be listed once). ' : '') . $message);
        }
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            throw new \InvalidArgumentException('The file must contain a YAML mapping with "format", "version" and "source".');
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
        foreach (self::mappingEntries($document['category_mappings'] ?? null, 'category_mappings') as $feed => $destination) {
            $feed = (string) $feed;
            // Feed values are matched like discovery stores them: names trimmed.
            $path = array_map('trim', self::optionalPath($feed, 'category_mappings: feed path "' . $feed . '"') ?? []);
            $categories[] = ['source_path' => $path, 'category' => self::optionalPath($destination, 'category_mappings: destination of "' . $feed . '"')];
        }
        $brands = [];
        foreach (self::mappingEntries($document['brand_mappings'] ?? null, 'brand_mappings') as $name => $destination) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 191) {
                throw new \InvalidArgumentException('Every feed brand name in "brand_mappings" must have 1 to 191 characters.');
            }
            if ($destination !== null && (!is_string($destination) || $destination === '')) {
                throw new \InvalidArgumentException('brand_mappings: the destination of "' . $name . '" must be a PrestaShop brand name or ~ (auto-create).');
            }
            $brands[] = ['source_name' => $name, 'manufacturer' => $destination];
        }

        return ['source' => $source, 'category_mappings' => $categories, 'brand_mappings' => $brands];
    }

    /** @return array<array-key, mixed> the entries of a "feed value: destination" map */
    private static function mappingEntries($rows, string $key): array
    {
        if ($rows === null) {
            return [];
        }
        if (!is_array($rows) || ($rows !== [] && array_is_list($rows)) || count($rows) > self::MAX_MAPPINGS) {
            throw new \InvalidArgumentException('"' . $key . '" must be a map of "feed value: destination" with at most ' . self::MAX_MAPPINGS . ' entries.');
        }

        return $rows;
    }

    /**
     * Resolves names to this store's IDs for a NEW source. Unmatched settings stay unset and mapping entries with an unmatched destination are skipped; both become warnings.
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
        $data = [];
        foreach (self::TEXT_SETTINGS as $key) {
            $data[$key] = $in[$key];
        }
        foreach (self::FLAG_SETTINGS as $key) {
            $data[$key] = $in[$key] ? 1 : 0;
        }
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

        [$categories, $brands] = self::resolveMappings($document, $lookups, $idLang, $warnings);

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
        foreach (self::TEXT_SETTINGS as $key) {
            if ($key !== 'technical_key' && array_key_exists($key, $in)) {
                $changes[$key] = $in[$key];
            }
        }
        foreach (self::FLAG_SETTINGS as $key) {
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

        [$categories, $brands] = self::resolveMappings($document, $lookups, $idLang, $warnings);

        return ['source' => $changes, 'category_mappings' => $categories, 'brand_mappings' => $brands, 'warnings' => self::capWarnings($warnings)];
    }
    /**
     * Resolves the mapping entries to IDs. An entry whose destination does not exist in this store is skipped with a warning,
     * so an existing row stays as it is and no new row is created for it. null destination = auto-create.
     *
     * @return array{0: list<array>, 1: list<array>} categories, brands
     */
    private static function resolveMappings(array $document, array $lookups, int $idLang, array &$warnings): array
    {
        $categories = [];
        foreach ($document['category_mappings'] as $row) {
            $label = 'Category mapping for "' . self::joinPath($row['source_path']) . '": category';
            $before = count($warnings);
            $id = $row['category'] === null ? null : self::categoryId($row['category'], $label, $lookups, $idLang, $warnings);
            if ($row['category'] !== null && $id === null) {
                $warnings[$before] .= ' The entry was left unchanged.';
                continue;
            }
            $categories[] = ['source_path' => $row['source_path'], 'id_category' => $id];
        }
        $brands = [];
        foreach ($document['brand_mappings'] as $row) {
            $before = count($warnings);
            $id = $row['manufacturer'] === null ? null : self::namedId($row['manufacturer'], $lookups['manufacturers'], 'Brand mapping for "' . $row['source_name'] . '": brand', $warnings);
            if ($row['manufacturer'] !== null && $id === null) {
                $warnings[$before] .= ' The entry was left unchanged.';
                continue;
            }
            $brands[] = ['source_name' => $row['source_name'], 'id_manufacturer' => $id];
        }

        return [$categories, $brands];
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

    /** The mapping-file form of a path: segments joined with " > ". A path that would not split back into the same segments cannot be written. */
    private static function pathKey(array $segments): string
    {
        $joined = self::joinPath($segments);
        if (self::splitPath($joined) !== array_values($segments)) {
            throw new \InvalidArgumentException('The category path "' . $joined . '" has a name containing " > " (or an edge that merges with the separator) and cannot be written in the YAML mappings. Rename that category.');
        }

        return $joined;
    }

    /** Cross-field rules of a complete source (also applied to the merge result of an overwrite). */
    private static function checkSource(array $source): array
    {
        $formats = \ProductImport\Service\Format\FeedFormats::choices();
        if (!isset($formats[$source['source_format'] ?? null])) {
            throw new \InvalidArgumentException('"source.source_format" must be one of: ' . implode(', ', array_keys($formats)) . '.');
        }
        $options = \ProductImport\Service\Format\FeedFormats::get($source['source_format'])->options();
        foreach (\ProductImport\Service\Format\FeedFormats::all() as $format) {
            foreach ($format->options() as $column => $option) {
                if (!isset($options[$column])) {
                    $source[$column] = null;
                } elseif ($option['required'] && trim((string) ($source[$column] ?? '')) === '') {
                    throw new \InvalidArgumentException($option['label'] . ' is required for ' . $formats[$source['source_format']] . ' sources.');
                }
            }
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
        foreach (['location', 'xml_item_path', 'filter_expression', 'default_manufacturer', 'supplier'] as $key) {
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
        foreach (['name', 'technical_key', 'identifier_field', 'location', 'xml_item_path', 'filter_expression'] as $key) {
            if (isset($source[$key])) {
                $source[$key] = trim($source[$key]);
            }
        }
        foreach (['location', 'filter_expression'] as $key) {
            if (($source[$key] ?? null) === '') {
                $source[$key] = null;
            }
        }
        if (!$partial) {
            $source = self::checkSource($source);
        } else {
            if (isset($source['source_format']) && !\ProductImport\Service\Format\FeedFormats::has($source['source_format'])) {
                throw new \InvalidArgumentException('"source.source_format" must be one of: ' . implode(', ', array_keys(\ProductImport\Service\Format\FeedFormats::choices())) . '.');
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
        $location = trim((string) ($data['location'] ?? ''));
        if ($location === '') {
            throw new \InvalidArgumentException('Provide a source: an http:// or https:// URL, or a file path inside the import folder.');
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]+):/i', $location, $scheme) === 1) {
            $isUrl = in_array(strtolower($scheme[1]), ['http', 'https'], true) && str_starts_with(substr($location, strlen($scheme[1]) + 1), '//');
            if (!$isUrl || !filter_var($location, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Source must be a valid HTTP or HTTPS URL or a file path; "' . $scheme[1] . ':" locations are not supported.');
            }
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
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('"' . $label . '" must be a category path like "Home > Sport" (names joined with " > "; a name that itself contains " > " cannot be written) or null.');
        }
        $value = self::splitPath($value);
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
