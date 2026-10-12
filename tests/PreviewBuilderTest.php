<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\AttributeResolver;
use ProductImport\Service\CategoryPathNormalizer;
use ProductImport\Service\CategoryResolver;
use ProductImport\Service\ExpressionEvaluator;
use ProductImport\Service\ManufacturerResolver;
use ProductImport\Service\PreviewBuilder;
use ProductImport\Service\ProductFieldMapper;
use ProductImport\Service\ProductFilter;
use ProductImport\Service\FeatureMapper;
use ProductImport\Service\VariantFieldMapper;

require_once __DIR__ . '/FeatureFakes.php';

class PreviewBuilderTest extends TestCase
{
    private function builder(?FakeFeatureMappings $mappings = null): PreviewBuilder
    {
        $evaluator = new ExpressionEvaluator();
        $categories = new class () extends CategoryResolver {
            public function __construct()
            {
            }

            public function resolve(array $paths, int $source, ?int $root, bool $commit = true, ?int $defaultId = null): array
            {
                TestCase::assertFalse($commit);
                TestCase::assertSame(23, $defaultId);
                TestCase::assertSame(7, $source);
                TestCase::assertSame(9, $root);
                return [['path' => $paths[0], 'id_category' => null, 'auto_create' => true]];
            }
        };
        $manufacturers = new class () extends ManufacturerResolver {
            public function resolve(?string $name, int $idSource, bool $commit = true, ?int $defaultId = null): int|array|null
            {
                TestCase::assertSame(24, $defaultId);
                TestCase::assertSame(7, $idSource);
                TestCase::assertFalse($commit);
                if ($name === 'failure') {
                    throw new \RuntimeException('Manufacturer lookup failed');
                }
                return ['name' => $name, 'id_manufacturer' => $name ? 12 : null, 'auto_create' => false];
            }
        };
        $attributes = new class () extends AttributeResolver {
            public function resolve(string $groupName, string $valueName, bool $commit = true): array
            {
                TestCase::assertFalse($commit);
                return ['id_attribute_group' => 1, 'id_attribute' => null, 'create_group' => false, 'create_value' => true];
            }
        };
        return new PreviewBuilder(new ProductFilter($evaluator), new ProductFieldMapper($evaluator), new CategoryPathNormalizer(), $categories, $manufacturers, new VariantFieldMapper($evaluator), $attributes, new FeatureMapper($mappings ?? new FakeFeatureMappings(), new FakeFeatureResolver(), $evaluator));
    }

    private function build(array $source, array $item = []): array
    {
        return $this->builder()->build($this->withDefaults($source), $item);
    }

    private function withDefaults(array $source): array
    {
        return $source + ['id_source' => 7, 'root_category_id' => 9, 'default_id_category' => 23, 'default_id_manufacturer' => 24, 'unmapped_features' => 'create', 'id_lang_default' => 1, 'field_mapping' => []];
    }

    public function testTableMapsItemsWithPerCellErrorsAndVariantCounts(): void
    {
        $source = $this->withDefaults(['field_mapping' => ['name' => 'path(fields, "name")', 'price' => 'str(fields["variants"])', 'regular_price' => 'path(fields, "regular_price")'],
            'variant_mapping' => ['variants_expression' => 'path(fields, "variants")', 'attributes' => [['name' => 'Size', 'expression' => 'path(variant, "size")']], 'fields' => ['reference' => 'path(variant, "sku")']]]);
        $table = $this->builder()->table($source, [['name' => 'Board', 'variants' => [['sku' => 'A', 'size' => 'S'], ['sku' => 'B']]], 'bad', ['name' => 'Sail']], 20);
        self::assertSame(['name', 'price', 'regular_price'], $table['targets']);
        self::assertCount(3, $table['rows']);
        self::assertSame('Board', $table['rows'][0]['cells']['name']['value']);
        self::assertNull($table['rows'][0]['cells']['regular_price']['value']);
        self::assertNull($table['rows'][0]['cells']['regular_price']['error']);
        self::assertNotNull($table['rows'][0]['cells']['price']['error']);
        self::assertSame(2, $table['rows'][0]['variants']['total']);
        self::assertGreaterThan(0, $table['rows'][0]['variants']['errors']);
        self::assertSame('Item is not an object.', $table['rows'][1]['notes']['item']);
        self::assertSame(0, $table['rows'][2]['variants']['total']);
        self::assertCount(1, $this->builder()->table($source, [['name' => 'A'], ['name' => 'B']], 1)['rows']);
    }

    public function testTableFlagsInvalidEanAndReferenceCollisions(): void
    {
        $source = $this->withDefaults(['field_mapping' => ['name' => 'fields["name"]', 'reference' => 'fields["ref"]', 'ean13' => 'fields["ean"]']]);
        $items = [
            ['name' => 'A', 'ref' => 'R1', 'ean' => '4006381333931'],
            ['name' => 'B', 'ref' => 'R1', 'ean' => '4006381333932'],
            ['name' => 'C', 'ref' => 'TAKEN', 'ean' => null],
            ['name' => 'D', 'ref' => 'R4', 'ean' => ''],
        ];
        $table = $this->builder()->table($source, $items, 20, static fn (string $reference, array $item): ?int => $reference === 'TAKEN' ? 5 : null);
        $cells = array_column($table['rows'], 'cells');
        self::assertNull($cells[0]['reference']['error']);
        self::assertNull($cells[0]['ean13']['error']);
        self::assertSame('"R1" is already used by feed item 0 — not written', $cells[1]['reference']['error']);
        self::assertSame('invalid EAN-13 "4006381333932" (check digit) — not written', $cells[1]['ean13']['error']);
        self::assertSame('"TAKEN" is already used by product #5 — not written', $cells[2]['reference']['error']);
        self::assertNull($cells[2]['ean13']['error']);
        self::assertNull($cells[3]['reference']['error']);
        self::assertNull($cells[3]['ean13']['error']);
    }

    public function testTableReturnsVariantImpactAttributeErrorsAndTruncation(): void
    {
        $variants = [['sku' => 'S', 'size' => 'S', 'price' => 205, 'qty' => 2], ['sku' => 'M', 'size' => '', 'price' => 220, 'qty' => 3]];
        $variants = array_merge($variants, array_fill(0, 5, ['sku' => 'X', 'size' => 'L', 'price' => 200, 'qty' => 1]));
        $source = $this->withDefaults(['field_mapping' => ['name' => 'path(fields, "name")', 'price' => 'path(fields, "price")'],
            'variant_mapping' => ['variants_expression' => 'path(fields, "variants")', 'attributes' => [['name' => 'Size', 'expression' => 'path(variant, "size")']],
                'fields' => ['reference' => 'path(variant, "sku")', 'price' => 'path(variant, "price")', 'quantity' => 'path(variant, "qty")']]]);
        $row = $this->builder()->table($source, [['name' => 'Suit', 'price' => 200, 'variants' => $variants], ['name' => 'NoBase', 'variants' => $variants]])['rows'];
        $summary = $row[0]['variants'];
        self::assertSame(7, $summary['total']);
        self::assertSame(2, $summary['truncated']);
        self::assertCount(5, $summary['items']);
        self::assertSame(5.0, $summary['items'][0]['price_impact']);
        self::assertSame(20.0, $summary['items'][1]['price_impact']);
        self::assertSame('205', $summary['items'][0]['cells']['price']['value']);
        self::assertSame('S', $summary['items'][0]['cells']['reference']['value']);
        self::assertSame('2', $summary['items'][0]['cells']['quantity']['value']);
        self::assertSame(['name' => 'Size', 'value' => 'S', 'error' => null], $summary['items'][0]['attributes'][0]);
        self::assertSame('Attribute value is empty.', $summary['items'][1]['attributes'][0]['error']);
        self::assertGreaterThan(0, $summary['errors']);
        self::assertNull($row[1]['variants']['items'][0]['price_impact']);
    }

    public function testTableShowsListsFeaturesAndValidatesDeliveryCarriersRelatedAndMeta(): void
    {
        $source = $this->withDefaults(['identifier_field' => 'id', 'field_mapping' => [
            'name' => 'fields["name"]',
            'related_products' => 'fields["related"]',
            'features' => 'fields["features"]',
            'delivery_time' => 'fields["delivery"]',
            'carriers' => 'fields["carriers"]',
            'meta_title' => 'fields["meta"]',
            'on_sale' => 'true',
        ]]);
        $items = [
            ['id' => 'A', 'name' => 'One', 'related' => ['B', 'A', 'REF-1', 'GHOST'], 'features' => ['Color' => 'Red', 'Empty' => '', 'Size' => 'L'], 'delivery' => 'custom', 'carriers' => ['DPD', 'Nope', '7', '9'], 'meta' => str_repeat('x', 300)],
            ['id' => 'B', 'name' => 'Two', 'related' => 'A', 'features' => [['name' => 'Material', 'value' => 'Wood']], 'delivery' => '15', 'carriers' => [], 'meta' => 'short'],
            ['id' => 'C', 'name' => 'Three', 'delivery' => null],
            ['id' => 'D', 'name' => 'Four', 'delivery' => ''],
            ['id' => 'E', 'name' => 'Five', 'delivery' => 'Custom'],
            ['id' => 'F', 'name' => 'Six', 'delivery' => 2.0],
            ['id' => 'G', 'name' => 'Seven', 'delivery' => 15],
            ['id' => 'H', 'name' => 'Eight', 'delivery' => true],
        ];
        $owner = static fn (string $reference, array $item): ?int => $reference === 'REF-1' ? 5 : null;
        $carriers = [['id_reference' => 7, 'name' => 'DPD'], ['id_reference' => 8, 'name' => 'Pošta']];
        $rows = $this->builder()->table($source, $items, 20, $owner, $carriers)['rows'];
        $first = $rows[0]['cells'];
        self::assertSame('B, A, REF-1, GHOST', $first['related_products']['value']);
        self::assertNull($first['related_products']['error']);
        self::assertSame('not found yet (resolved after import): GHOST', $first['related_products']['note']);
        self::assertSame('Color: Red; Size: L', $first['features']['value']);
        self::assertNull($first['delivery_time']['error']);
        self::assertSame('"Nope", "9"', substr($first['carriers']['error'], strlen('unknown carrier: ')));
        self::assertStringContainsString('300 characters; truncated to 255', $first['meta_title']['note']);
        self::assertSame('true', $first['on_sale']['value']);
        $second = $rows[1]['cells'];
        self::assertNull($second['related_products']['note']);
        self::assertSame('Material: Wood', $second['features']['value']);
        self::assertSame('delivery_time must be none, default or custom (or 0, 1, 2); got "15"', $second['delivery_time']['error']);
        foreach ([2 => null, 3 => '', 4 => 'Custom', 5 => '2'] as $row => $text) {
            self::assertNull($rows[$row]['cells']['delivery_time']['error'], (string) $row);
            self::assertSame($text, $rows[$row]['cells']['delivery_time']['value'], (string) $row);
        }
        self::assertSame('delivery_time must be none, default or custom (or 0, 1, 2); got 15', $rows[6]['cells']['delivery_time']['error']);
        self::assertSame('delivery_time must be none, default or custom (or 0, 1, 2); got true', $rows[7]['cells']['delivery_time']['error']);
        self::assertNull($second['carriers']['error']);
        self::assertNull($second['meta_title']['note']);
    }

    public function testTableVariantCellsIncludeAvailabilityTexts(): void
    {
        $source = $this->withDefaults(['field_mapping' => ['name' => 'fields["name"]'],
            'variant_mapping' => ['variants_expression' => 'path(fields, "variants")', 'attributes' => [['name' => 'Size', 'expression' => 'path(variant, "size")']],
                'fields' => ['reference' => 'path(variant, "sku")', 'available_now' => '"In stock"', 'available_later' => 'path(variant, "later")']]]);
        $row = $this->builder()->table($source, [['name' => 'Suit', 'variants' => [['sku' => 'S', 'size' => 'S', 'later' => 'Soon']]]])['rows'][0];
        $cells = $row['variants']['items'][0]['cells'];
        self::assertSame('In stock', $cells['available_now']['value']);
        self::assertSame('Soon', $cells['available_later']['value']);
    }

    public function testAssemblesFieldsAndReadOnlyResolutions(): void
    {
        $result = $this->build(['field_mapping' => ['name' => 'fields["name"]', 'category_paths' => '[" Boards "]', 'manufacturer' => '"Acme"']], ['name' => 'Board']);
        self::assertTrue($result['filter']['should_import']);
        self::assertSame('No filter configured.', $result['filter']['reason']);
        self::assertSame('Board', $result['base']['values']['name']);
        self::assertSame([['path' => ['Boards'], 'id_category' => null, 'auto_create' => true]], $result['categories']);
        self::assertSame(12, $result['manufacturer']['id_manufacturer']);
        self::assertSame([], $result['errors']);
    }

    public function testFalseFilterStillMaps(): void
    {
        $result = $this->build(['filter_expression' => 'false', 'field_mapping' => ['name' => '"Board"']]);
        self::assertFalse($result['filter']['should_import']);
        self::assertSame('Filter evaluated false.', $result['filter']['reason']);
        self::assertSame('Board', $result['base']['values']['name']);
    }

    public function testBrokenFilterAndFieldErrorsAllSurface(): void
    {
        $result = $this->build(['filter_expression' => 'fields[', 'field_mapping' => ['bad' => '1 / 0', 'good' => '42']]);
        self::assertNull($result['filter']['should_import']);
        self::assertArrayHasKey('filter', $result['errors']);
        self::assertArrayHasKey('bad', $result['base']['errors']);
        self::assertSame(42, $result['base']['values']['good']);
    }

    public function testVariantCapAndAllMappingErrors(): void
    {
        $result = $this->build(['variant_mapping' => [
            'variants_expression' => 'fields["variants"]',
            'attributes' => [['name' => 'Size', 'expression' => 'variant["size"]'], ['name' => 'Broken', 'expression' => 'variant[']],
            'fields' => ['reference' => 'variant["size"]', 'bad' => '1 / 0'],
        ]], ['variants' => array_fill(0, 8, ['size' => 'M'])]);
        self::assertSame(8, $result['variants']['total']);
        self::assertSame(3, $result['variants']['truncated']);
        self::assertCount(5, $result['variants']['items']);
        foreach ($result['variants']['items'] as $variant) {
            self::assertSame('M', $variant['fields']['values']['reference']);
            self::assertArrayHasKey('bad', $variant['fields']['errors']);
            self::assertArrayHasKey('Broken', $variant['attributes']['errors']);
            self::assertTrue($variant['resolutions']['Size']['create_value']);
            self::assertArrayNotHasKey('Broken', $variant['resolutions']);
        }
    }

    public function testBrokenVariantsListSurfaces(): void
    {
        $result = $this->build(['variant_mapping' => ['variants_expression' => '"bad"']]);
        self::assertArrayHasKey('variants', $result['errors']);
        self::assertSame([], $result['variants']['items']);
    }

    public function testEmptyAttributesAndResolverFailureSurface(): void
    {
        $result = $this->build(['field_mapping' => ['manufacturer' => '"failure"'], 'variant_mapping' => [
            'variants_expression' => '[[]]', 'attributes' => [['name' => 'Size', 'expression' => 'null']], 'fields' => [],
        ]]);
        self::assertSame('Manufacturer lookup failed', $result['errors']['manufacturer']);
        self::assertSame('Attribute value is empty.', $result['variants']['items'][0]['attributes']['errors']['Size']);
    }

    public function testDisabledAndEmptyVariants(): void
    {
        foreach ([null, [], ['variants_expression' => ' '], ['variants_expression' => '[]', 'attributes' => [], 'fields' => []]] as $mapping) {
            $result = $this->build(['variant_mapping' => $mapping]);
            self::assertSame(['total' => 0, 'truncated' => 0, 'items' => []], $result['variants']);
        }
    }

    public function testFeaturesColumnShowsTheFinalMappedFeaturesAndNotesWhatIsLeftOut(): void
    {
        $mappings = new FakeFeatureMappings();
        $mappings->rows['Finbox'] = FakeFeatureMappings::row('Finbox', 'create', null, 'Fin box');
        $mappings->rows['Fin Box'] = FakeFeatureMappings::row('Fin Box', 'create', null, 'Fin box');
        $mappings->rows['Weight (kg)'] = FakeFeatureMappings::row('Weight (kg)', 'map', 6, null, "value == 'TBA' ? null : value");
        $mappings->rows['Colour'] = FakeFeatureMappings::row('Colour', 'ignore');
        $source = $this->withDefaults(['unmapped_features' => 'ignore', 'identifier_field' => 'id', 'field_mapping' => ['name' => 'fields["name"]', 'features' => 'fields["features"]']]);
        $unmapped = ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6', 'g' => '7'];
        $items = [
            ['id' => 'A', 'name' => 'One', 'features' => ['Finbox' => 'US-BOX', 'Fin Box' => 'US-Box', 'Colour' => 'Red', 'Weight (kg)' => 'TBA'] + $unmapped],
            ['id' => 'B', 'name' => 'Two', 'features' => ['Weight (kg)' => '12']],
            ['id' => 'C', 'name' => 'Three', 'features' => 'text'],
        ];
        $rows = $this->builder($mappings)->table($source, $items)['rows'];
        $first = $rows[0]['cells']['features'];
        self::assertSame('Fin box: US-BOX', $first['value']);
        self::assertNull($first['error']);
        self::assertStringContainsString('1 ignored: Colour', $first['note']);
        self::assertStringContainsString('7 not mapped (source setting: ignore): a, b, c, d, e, …', $first['note']);
        self::assertStringContainsString('"Finbox" and "Fin Box" both map to the feature "Fin box"; "Finbox" is used', $first['note']);
        self::assertSame('Weight: 12', $rows[1]['cells']['features']['value']);
        self::assertNull($rows[1]['cells']['features']['note']);
        self::assertSame('features must be a map of name to value, or a list of {name, value}.', $rows[2]['cells']['features']['error']);
        self::assertSame([], $mappings->writes, 'the preview never writes');
    }
}
