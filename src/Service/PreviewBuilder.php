<?php

namespace ProductImport\Service;

class PreviewBuilder
{
    private $filter;
    private $mapper;
    private $normalizer;
    private $categories;
    private $manufacturers;
    private $variants;
    private $attributes;
    public const VARIANT_LIMIT = 5;
    public const TABLE_LIMIT = 20;
    public const DISPLAY_LIMIT = 200;

    public function __construct(
        ProductFilter $filter,
        ProductFieldMapper $mapper,
        CategoryPathNormalizer $normalizer,
        CategoryResolver $categories,
        ManufacturerResolver $manufacturers,
        VariantFieldMapper $variants,
        AttributeResolver $attributes
    ) {
        $this->filter = $filter;
        $this->mapper = $mapper;
        $this->normalizer = $normalizer;
        $this->categories = $categories;
        $this->manufacturers = $manufacturers;
        $this->variants = $variants;
        $this->attributes = $attributes;
    }

    /** Mapping columns must already be decoded arrays (variant_mapping may be null). No writes. */
    public function build(array $source, array $item): array
    {
        $result = ['filter' => [], 'base' => [], 'categories' => [], 'manufacturer' => null,
            'variants' => ['total' => 0, 'truncated' => 0, 'items' => []], 'errors' => []];
        $expression = $source['filter_expression'] ?? null;
        try {
            $include = $this->filter->shouldImport($item, $expression);
            $result['filter'] = ['should_import' => $include, 'expression' => $expression,
                'reason' => trim($expression ?? '') === '' ? 'No filter configured.' : 'Filter evaluated ' . ($include ? 'true.' : 'false.')];
        } catch (\Throwable $error) {
            $result['filter'] = ['should_import' => null, 'expression' => $expression, 'reason' => 'Filter evaluation failed.'];
            $result['errors']['filter'] = $error->getMessage();
        }
        // Continue even when excluded or broken so configuration mistakes remain visible.
        $result['base'] = $this->mapper->map($item, $source['field_mapping']);
        if (($warning = Gtin::warning($result['base']['values']['ean13'] ?? null)) !== null) {
            $result['base']['errors']['ean13'] = $warning;
        }
        $values = $result['base']['values'];
        if (isset($values['category_paths'])) {
            try {
                $paths = $this->normalizer->normalize($values['category_paths']);
                $result['categories'] = $this->categories->resolve($paths, (int) $source['id_source'], isset($source['root_category_id']) ? (int) $source['root_category_id'] : null, false, isset($source['default_id_category']) ? (int) $source['default_id_category'] : null);
            } catch (\Throwable $error) {
                $result['errors']['categories'] = $error->getMessage();
            }
        }
        try {
            $result['manufacturer'] = $this->manufacturers->resolve($values['manufacturer'] ?? null, (int) $source['id_source'], false, isset($source['default_id_manufacturer']) ? (int) $source['default_id_manufacturer'] : null);
        } catch (\Throwable $error) {
            $result['errors']['manufacturer'] = $error->getMessage();
        }
        $configuration = $source['variant_mapping'] ?? null;
        if (!$configuration || trim($configuration['variants_expression'] ?? '') === '') {
            return $result;
        }
        try {
            $variants = $this->variants->variants($item, $configuration['variants_expression']);
            $result['variants']['total'] = count($variants);
            $result['variants']['truncated'] = max(0, count($variants) - self::VARIANT_LIMIT);
            foreach (array_slice($variants, 0, self::VARIANT_LIMIT) as $index => $variant) {
                $attributes = $this->variants->mapAttributes($item, $variant, $configuration['attributes']);
                $fields = $this->variants->mapFields($item, $variant, $configuration['fields']);
                if (($warning = Gtin::warning($fields['values']['ean13'] ?? null)) !== null) {
                    $fields['errors']['ean13'] = $warning;
                }
                $resolutions = [];
                foreach ($attributes['values'] as $group => $value) {
                    if (isset($attributes['errors'][$group])) {
                        continue;
                    }
                    try {
                        if ($value === null || trim($value) === '') {
                            throw new \InvalidArgumentException('Attribute value is empty.');
                        }
                        $resolutions[$group] = $this->attributes->resolve((string) $group, $value, false);
                    } catch (\Throwable $error) {
                        $attributes['errors'][$group] = $error->getMessage();
                    }
                }
                $result['variants']['items'][] = ['index' => $index, 'attributes' => $attributes, 'fields' => $fields, 'resolutions' => $resolutions];
            }
        } catch (\Throwable $error) {
            $result['errors']['variants'] = $error->getMessage();
        }

        return $result;
    }

    /**
     * Dry-run preview of many items with the (possibly unsaved) mapping in $source: one row per item,
     * one cell per mapped target. Cell values are display strings (null stays null). No writes.
     *
     * @param (callable(string, array): ?int)|null $referenceOwner id of an existing product, other than the one linked
     *                                                             to the item, that holds the reference; null skips the check
     *
     * @return array{targets: list<string>, rows: list<array<string, mixed>>}
     */
    public function table(array $source, array $items, int $limit = self::TABLE_LIMIT, ?callable $referenceOwner = null): array
    {
        $targets = array_keys($source['field_mapping']);
        $rows = [];
        $previewed = array_slice($items, 0, $limit, true);
        $duplicates = [];
        if (isset($source['field_mapping']['reference'])) {
            $references = [];
            foreach ($previewed as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                try {
                    $references[$index] = $this->mapper->map($item, ['reference' => $source['field_mapping']['reference']])['values']['reference'] ?? null;
                } catch (\Throwable) {
                }
            }
            $duplicates = ReferenceCheck::feedDuplicates($references);
        }
        foreach ($previewed as $index => $item) {
            if (!is_array($item)) {
                $rows[] = ['index' => $index, 'included' => null, 'cells' => [], 'notes' => ['item' => 'Item is not an object.'],
                    'variants' => ['total' => 0, 'truncated' => 0, 'errors' => 0, 'items' => []]];
                continue;
            }
            $result = $this->build($source, $item);
            $reference = $result['base']['values']['reference'] ?? null;
            if (isset($duplicates[$index])) {
                $result['base']['errors']['reference'] = '"' . $reference . '" is already used by feed item ' . $duplicates[$index] . ' — not written';
            } elseif ($referenceOwner !== null && is_scalar($reference) && trim((string) $reference) !== '' && !isset($result['base']['errors']['reference'])) {
                $owner = $referenceOwner((string) $reference, $item);
                if ($owner !== null) {
                    $result['base']['errors']['reference'] = ReferenceCheck::usedByProduct((string) $reference, $owner);
                }
            }
            $cells = [];
            foreach ($targets as $target) {
                $cells[$target] = ['value' => self::display($result['base']['values'][$target] ?? null), 'error' => $result['base']['errors'][$target] ?? null];
            }
            $variantErrors = isset($result['errors']['variants']) ? 1 : 0;
            $basePrice = $result['base']['values']['price'] ?? null;
            $basePrice = is_numeric($basePrice) ? (float) $basePrice : null;
            $variantRows = [];
            foreach ($result['variants']['items'] as $variant) {
                $variantErrors += count($variant['fields']['errors']) + count($variant['attributes']['errors']);
                $variantCells = [];
                foreach (['reference', 'ean13', 'mpn', 'price', 'quantity', 'active', 'image'] as $field) {
                    $variantCells[$field] = ['value' => self::display($variant['fields']['values'][$field] ?? null), 'error' => $variant['fields']['errors'][$field] ?? null];
                }
                $attributes = [];
                foreach ($variant['attributes']['values'] as $group => $value) {
                    $attributes[] = ['name' => (string) $group, 'value' => self::display($value), 'error' => $variant['attributes']['errors'][$group] ?? null];
                }
                // Mirrors CombinationImporter: impact = variant price - base price.
                $price = $variant['fields']['values']['price'] ?? null;
                $impact = $basePrice !== null && is_numeric($price) ? round((float) $price - $basePrice, 6) : null;
                $variantRows[] = ['index' => $variant['index'], 'attributes' => $attributes, 'cells' => $variantCells, 'price_impact' => $impact];
            }
            $rows[] = ['index' => $index, 'included' => $result['filter']['should_import'] ?? null, 'cells' => $cells,
                'notes' => $result['errors'],
                'variants' => ['total' => $result['variants']['total'], 'truncated' => $result['variants']['truncated'], 'errors' => $variantErrors, 'items' => $variantRows]];
        }

        return ['targets' => $targets, 'rows' => $rows];
    }

    private static function display($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            $text = $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[unprintable]';
        }

        return mb_substr($text, 0, self::DISPLAY_LIMIT);
    }
}
