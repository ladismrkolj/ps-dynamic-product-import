<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\SourceConfigTransfer;
use Symfony\Component\Yaml\Yaml;

class SourceConfigTransferTest extends TestCase
{
    private static function source(array $override = []): array
    {
        return $override + [
            'name' => 'Šport shop', 'technical_key' => 'sport_shop', 'location' => 'https://example.com/feed.json',
            'source_format' => 'json', 'xml_item_path' => null, 'identifier_field' => 'id', 'filter_expression' => 'fields.stock > 0',
            'field_mapping' => '{"name":"fields.title","price":"fields.price"}',
            'variant_mapping' => '{"variants_expression":"fields.variants","attributes":[{"name":"Size","expression":"variant.size"}],"fields":{"reference":"variant.sku"}}',
            'active' => '1', 'deactivate_missing' => '0', 'id_lang_default' => '2', 'root_category_id' => '5', 'default_id_category' => '6',
            'default_id_manufacturer' => '7', 'id_supplier' => '8',
        ];
    }

    private static function lookups(): array
    {
        $paths = [5 => ['Home', 'Sport'], 6 => ['Home', 'Sport', 'Boards']];
        $manufacturers = [7 => 'Fanatic', 9 => 'Duotone'];

        return [
            'language_iso' => static function (int $id) {
                return $id === 2 ? 'sl' : null;
            },
            'category_path' => static function (int $id) use ($paths) {
                return $paths[$id] ?? null;
            },
            'manufacturer_name' => static function (int $id) use ($manufacturers) {
                return $manufacturers[$id] ?? null;
            },
            'supplier_name' => static function (int $id) {
                return $id === 8 ? 'Acme' : null;
            },
        ];
    }

    private static function export(): string
    {
        $categories = [['source_path' => '["Water","Boards"]', 'id_category' => '6'], ['source_path' => '["Misc"]', 'id_category' => null]];
        $brands = [['source_name' => 'Duotone', 'id_manufacturer' => '9'], ['source_name' => 'Other', 'id_manufacturer' => null]];
        $document = (new SourceConfigTransfer())->build(self::source(), $categories, $brands, self::lookups(), '0.15.0', '2026-01-02T03:04:05+00:00');

        return SourceConfigTransfer::encode($document);
    }

    /** Store tree: Home(2) > Sport(5) > Boards(6); root is 1. */
    private static function importLookups(array $overrides = []): array
    {
        $tree = [1 => [2 => 'Home'], 2 => [5 => 'Sport'], 5 => [6 => 'Boards']];

        return $overrides + [
            'language_id' => static function (string $iso) {
                return $iso === 'sl' ? 2 : null;
            },
            'default_language_id' => static function () {
                return 1;
            },
            'root_category_id' => 1,
            'category_children' => static function (int $parent) use ($tree) {
                return $tree[$parent] ?? [];
            },
            'manufacturers' => static function (string $name) {
                return ['Fanatic' => [7 => 'Fanatic'], 'Duotone' => [9 => 'Duotone']][$name] ?? [];
            },
            'suppliers' => static function (string $name) {
                return $name === 'Acme' ? [8 => 'Acme'] : [];
            },
        ];
    }

    public function testBuildParseResolveRoundTrip(): void
    {
        $json = self::export();
        self::assertStringContainsString('# Product Import source configuration', $json);
        self::assertStringContainsString("\n  'Water > Boards': 'Home > Sport > Boards'\n", $json);
        self::assertStringContainsString("\n  'Misc': ~\n", $json);
        self::assertStringNotContainsString('token', strtolower($json));
        $decoded = Yaml::parse($json);
        self::assertSame('productimport-source', $decoded['format']);
        self::assertSame(1, $decoded['version']);
        self::assertSame('Home > Sport > Boards', $decoded['source']['default_category']);
        self::assertSame(['Water > Boards' => 'Home > Sport > Boards', 'Misc' => null], $decoded['category_mappings']);
        self::assertSame(['Duotone' => 'Duotone', 'Other' => null], $decoded['brand_mappings']);
        self::assertSame('https://example.com/feed.json', $decoded['source']['location']);
        self::assertArrayNotHasKey('json_url', $decoded['source']);
        self::assertSame(['name' => 'fields.title', 'price' => 'fields.price'], $decoded['source']['field_mapping']);
        self::assertSame('sl', $decoded['source']['language']);
        self::assertSame('Acme', $decoded['source']['supplier']);

        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolve($transfer->parse($json), self::importLookups(), static function () {
            return false;
        });

        self::assertSame([], $result['warnings']);
        $source = $result['source'];
        $original = self::source();
        self::assertSame('Šport shop', $source['name']);
        self::assertSame('sport_shop', $source['technical_key']);
        self::assertSame($original['field_mapping'], $source['field_mapping']);
        self::assertSame(json_encode(json_decode($original['variant_mapping'])), json_encode(json_decode($source['variant_mapping'])));
        self::assertSame([2, 5, 6, 7, 8, 1, 0], [$source['id_lang_default'], $source['root_category_id'], $source['default_id_category'], $source['default_id_manufacturer'], $source['id_supplier'], $source['active'], $source['deactivate_missing']]);
        self::assertSame('fields.stock > 0', $source['filter_expression']);
        self::assertSame([['source_path' => ['Water', 'Boards'], 'id_category' => 6], ['source_path' => ['Misc'], 'id_category' => null]], $result['category_mappings']);
        self::assertSame([['source_name' => 'Duotone', 'id_manufacturer' => 9], ['source_name' => 'Other', 'id_manufacturer' => null]], $result['brand_mappings']);
    }

    public function testEmptyFieldMappingStaysAnObject(): void
    {
        $document = (new SourceConfigTransfer())->build(self::source(['field_mapping' => '{}', 'variant_mapping' => null]), [], [], self::lookups(), '0.15.0', 'now');
        $parsed = (new SourceConfigTransfer())->parse(SourceConfigTransfer::encode($document));
        self::assertSame('{}', $parsed['source']['field_mapping']);
        self::assertNull($parsed['source']['variant_mapping']);
    }

    public function testRejectsUnknownVersionAndFormat(): void
    {
        $document = Yaml::parse(self::export());
        $transfer = new SourceConfigTransfer();
        foreach ([2, '1', null] as $version) {
            $bad = ['version' => $version] + $document;
            try {
                $transfer->parse(json_encode($bad));
                self::fail('Version accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Unsupported export version', $error->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a Product Import source export');
        $transfer->parse(json_encode(['format' => 'other'] + $document));
    }

    #[DataProvider('invalidDocuments')]
    public function testRejectsInvalidDocuments(callable $mutate, string $message): void
    {
        $document = Yaml::parse(self::export());
        $document = $mutate($document);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new SourceConfigTransfer())->parse(json_encode($document));
    }

    public static function invalidDocuments(): array
    {
        return [
            'name type' => [static function (array $d) {
                $d['source']['name'] = 5;
                return $d;
            }, 'source.name'],
            'bad key' => [static function (array $d) {
                $d['source']['technical_key'] = 'Bad Key';
                return $d;
            }, 'lowercase slug'],
            'key too long' => [static function (array $d) {
                $d['source']['technical_key'] = str_repeat('a', 65);
                return $d;
            }, 'technical_key'],
            'no location' => [static function (array $d) {
                $d['source']['location'] = null;
                return $d;
            }, 'Provide a source'],
            'ftp url' => [static function (array $d) {
                $d['source']['location'] = 'ftp://example.com/x';
                return $d;
            }, 'HTTP or HTTPS'],
            'file scheme' => [static function (array $d) {
                $d['source']['location'] = 'file:///etc/passwd';
                return $d;
            }, '"file:" locations are not supported'],
            'php wrapper' => [static function (array $d) {
                $d['source']['location'] = 'php://filter/resource=x';
                return $d;
            }, '"php:" locations are not supported'],
            'format' => [static function (array $d) {
                $d['source']['source_format'] = 'csv';
                return $d;
            }, 'source_format'],
            'xml without path' => [static function (array $d) {
                $d['source']['source_format'] = 'xml';
                return $d;
            }, 'XML item path'],
            'active type' => [static function (array $d) {
                $d['source']['active'] = 'yes';
                return $d;
            }, 'source.active'],
            'mapping expression type' => [static function (array $d) {
                $d['source']['field_mapping']['name'] = 3;
                return $d;
            }, 'field_mapping'],
            'mapping empty expression' => [static function (array $d) {
                $d['source']['field_mapping']['name'] = ' ';
                return $d;
            }, 'field_mapping'],
            'mapping as list' => [static function (array $d) {
                $d['source']['field_mapping'] = ['a'];
                return $d;
            }, 'field_mapping'],
            'variants without reference' => [static function (array $d) {
                $d['source']['variant_mapping']['fields'] = ['price' => 'x'];
                return $d;
            }, 'reference'],
            'path type' => [static function (array $d) {
                $d['source']['root_category'] = 5;
                return $d;
            }, 'source.root_category'],
            'category mapping with empty segment' => [static function (array $d) {
                $d['category_mappings'] = ['A >  > B' => null];
                return $d;
            }, 'non-empty names'],
            'brand destination type' => [static function (array $d) {
                $d['brand_mappings'] = ['x' => 5];
                return $d;
            }, 'destination of "x"'],
            'category mappings as list' => [static function (array $d) {
                $d['category_mappings'] = [['feed' => 'a', 'category' => 'b']];
                return $d;
            }, 'must be a map'],
            'destination as list' => [static function (array $d) {
                $d['category_mappings'] = ['A' => ['Home', 'Sport']];
                return $d;
            }, 'category path like'],
        ];
    }

    public function testRejectsNonObjectAndOversizedInput(): void
    {
        $transfer = new SourceConfigTransfer();
        foreach (['not json', '[1,2]', '"x"', ''] as $input) {
            try {
                $transfer->parse($input);
                self::fail('Accepted: ' . $input);
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('larger than');
        $transfer->parse(str_repeat(' ', SourceConfigTransfer::MAX_BYTES + 1));
    }

    public function testKeyCollisionAddsSuffixAndImportedName(): void
    {
        $taken = ['sport_shop' => true, 'sport_shop_2' => true];
        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolve($transfer->parse(self::export()), self::importLookups(), static function (string $key) use ($taken) {
            return isset($taken[$key]);
        });
        self::assertSame('sport_shop_3', $result['source']['technical_key']);
        self::assertSame('Šport shop (imported)', $result['source']['name']);
        self::assertStringContainsString('sport_shop_3', $result['warnings'][0]);
    }

    public function testUniqueKeyKeepsSlugValidAndWithinLimit(): void
    {
        self::assertSame(['free', false], SourceConfigTransfer::uniqueKey('free', static function () {
            return false;
        }));
        $long = str_repeat('a', 63) . '-b';
        $taken = [$long => true];
        [$key, $changed] = SourceConfigTransfer::uniqueKey($long, static function (string $k) use ($taken) {
            return isset($taken[$k]);
        });
        self::assertTrue($changed);
        self::assertLessThanOrEqual(64, strlen($key));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/D', $key);
        [$trimmed] = SourceConfigTransfer::uniqueKey(str_repeat('a', 62) . '-b', static function (string $k) {
            return strlen($k) === 64 && substr($k, -2) !== '_2';
        });
        self::assertSame(str_repeat('a', 62) . '_2', $trimmed);
    }

    public function testUnmatchedNamesStayUnsetWithWarnings(): void
    {
        $lookups = self::importLookups([
            'language_id' => static function () {
                return null;
            },
            'manufacturers' => static function () {
                return [];
            },
            'suppliers' => static function () {
                return [];
            },
            'category_children' => static function (int $parent) {
                return $parent === 1 ? [2 => 'Home'] : [];
            },
        ]);
        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolve($transfer->parse(self::export()), $lookups, static function () {
            return false;
        });
        $source = $result['source'];
        self::assertSame(1, $source['id_lang_default']);
        self::assertNull($source['root_category_id']);
        self::assertNull($source['default_id_category']);
        self::assertNull($source['default_id_manufacturer']);
        self::assertNull($source['id_supplier']);
        self::assertSame([['source_path' => ['Misc'], 'id_category' => null]], $result['category_mappings'], 'entry with a missing destination is not created');
        self::assertSame([['source_name' => 'Other', 'id_manufacturer' => null]], $result['brand_mappings']);
        $text = implode("\n", $result['warnings']);
        foreach (['Language "sl"', 'Root category "Home > Sport"', 'Default category', 'Default brand "Fanatic"', 'Supplier "Acme"', 'Brand mapping for "Duotone"', 'Category mapping for "Water > Boards"'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
    }

    public function testCategoryPathMatchingRules(): void
    {
        $tree = [1 => [2 => 'Home', 3 => 'home'], 2 => [5 => 'Sport', 6 => 'Sport'], 3 => [7 => 'Boards']];
        $children = static function (int $parent) use ($tree) {
            return $tree[$parent] ?? [];
        };
        self::assertSame([7, ''], SourceConfigTransfer::matchCategoryPath(['home', 'Boards'], 1, 1, $children));
        self::assertSame(2, SourceConfigTransfer::matchCategoryPath(['Home'], 1, 1, $children)[0]);
        self::assertNull(SourceConfigTransfer::matchCategoryPath(['Home', 'Sport'], 1, 1, $children)[0], 'ambiguous sibling names do not match');
        self::assertStringContainsString('ambiguous', SourceConfigTransfer::matchCategoryPath(['Home', 'Sport'], 1, 1, $children)[1]);
        [$id, $problem] = SourceConfigTransfer::matchCategoryPath(['Home', 'Nope', 'Deeper'], 1, 1, $children);
        self::assertNull($id);
        self::assertStringContainsString('stopped at "Nope"', $problem);
        self::assertNull(SourceConfigTransfer::matchCategoryPath(['HOME'], 1, 1, $children)[0], 'matching is case-sensitive');
    }

    public function testMatchNameIsExact(): void
    {
        self::assertSame([4, ''], SourceConfigTransfer::matchName('Fanatic', [4 => 'Fanatic', 5 => 'fanatic']));
        self::assertNull(SourceConfigTransfer::matchName('Fanatic', [4 => 'fanatic'])[0]);
        self::assertNull(SourceConfigTransfer::matchName('Fanatic', [4 => 'Fanatic', 5 => 'Fanatic'])[0]);
    }

    private static function existing(): array
    {
        return ['id_source' => '3', 'technical_key' => 'other_key', 'name' => 'Local name', 'location' => 'https://local.example/feed.json',
            'source_format' => 'json', 'xml_item_path' => null, 'identifier_field' => 'sku', 'filter_expression' => 'fields.x', 'field_mapping' => '{"name":"fields.n"}',
            'variant_mapping' => null, 'active' => '1', 'deactivate_missing' => '1', 'id_lang_default' => '1', 'root_category_id' => '2', 'default_id_category' => '2',
            'default_id_manufacturer' => '9', 'id_supplier' => null];
    }

    public function testYamlRoundTripEqualsTheModel(): void
    {
        $source = self::source(['filter_expression' => "fields.stock > 0\n  and fields.price > 1", 'identifier_field' => '123']);
        $categories = [['source_path' => '["Sails","B"]', 'id_category' => '6'], ['source_path' => '["Misc"]', 'id_category' => null]];
        $document = (new SourceConfigTransfer())->build($source, $categories, [['source_name' => 'Duotone', 'id_manufacturer' => '9']], self::lookups(), '0.15.0', '2026-01-02T03:04:05+00:00');
        $yaml = SourceConfigTransfer::encode($document);
        self::assertSame($document, Yaml::parse($yaml));
        $parsed = (new SourceConfigTransfer())->parse($yaml);
        self::assertSame(['Sails', 'B'], $parsed['category_mappings'][0]['source_path']);
        self::assertSame(['Home', 'Sport', 'Boards'], $parsed['category_mappings'][0]['category']);
        self::assertNull($parsed['category_mappings'][1]['category']);
        self::assertSame('123', $parsed['source']['identifier_field']);
        self::assertStringContainsString('and fields.price > 1', $parsed['source']['filter_expression']);
    }

    public function testPathSplitAndJoinKeepSurroundingSpaces(): void
    {
        self::assertSame(['Home', 'Sport'], SourceConfigTransfer::splitPath('Home > Sport'));
        self::assertSame(['Home', ' Sails', 'Big '], SourceConfigTransfer::splitPath('Home >  Sails > Big '));
        self::assertSame('Home >  Sails > Big ', SourceConfigTransfer::joinPath(['Home', ' Sails', 'Big ']));
        self::assertSame(['Single'], SourceConfigTransfer::splitPath('Single'));
        foreach ([['a', ' b', 'c  '], ['x']] as $path) {
            self::assertSame($path, SourceConfigTransfer::splitPath(SourceConfigTransfer::joinPath($path)));
        }
    }

    public function testOverwriteUpsertsListedEntriesAndKeepsTheRest(): void
    {
        $yaml = "format: productimport-source\nversion: 1\nsource:\n  name: New name\n  technical_key: renamed\n  default_manufacturer: ~\n  default_category: Home > Sport\n"
            . "category_mappings:\n  Water > Boards: Home > Sport > Boards\n  Misc: ~\n  Gone: Nope > Nothing\n"
            . "brand_mappings:\n  Duotone: Duotone\n  Other: ~\n";
        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolveOverwrite($transfer->parse($yaml, true), self::existing(), self::importLookups());

        self::assertEquals(['name' => 'New name', 'default_id_manufacturer' => null, 'default_id_category' => 5], $result['source']);
        self::assertArrayNotHasKey('technical_key', $result['source']);
        self::assertSame([['source_path' => ['Water', 'Boards'], 'id_category' => 6], ['source_path' => ['Misc'], 'id_category' => null]], $result['category_mappings']);
        self::assertSame([['source_name' => 'Duotone', 'id_manufacturer' => 9], ['source_name' => 'Other', 'id_manufacturer' => null]], $result['brand_mappings']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('Category mapping for "Gone"', $result['warnings'][0]);
    }

    public function testOverwriteReplacesMappingsAndRevalidatesTheMergedSource(): void
    {
        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolveOverwrite($transfer->parse("format: productimport-source\nversion: 1\nsource:\n  field_mapping:\n    price: fields.price\n  language: xx\n", true), self::existing(), self::importLookups());
        self::assertSame(['field_mapping' => '{"price":"fields.price"}'], $result['source']);
        self::assertStringContainsString('Language "xx"', $result['warnings'][0]);
        self::assertSame([], $result['category_mappings']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('XML item path');
        $transfer->resolveOverwrite($transfer->parse("format: productimport-source\nversion: 1\nsource:\n  source_format: xml\n", true), self::existing(), self::importLookups());
    }

    public function testPartialParseStillValidatesPresentValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source.active');
        (new SourceConfigTransfer())->parse("format: productimport-source\nversion: 1\nsource:\n  active: maybe\n", true);
    }

    public function testUnknownVersionRejectedInYaml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported export version 2');
        (new SourceConfigTransfer())->parse("format: productimport-source\nversion: 2\nsource: {}\n", true);
    }

    public function testMappingMapsRoundTripAndTildeResetsToAutoCreate(): void
    {
        $yaml = self::export();
        $parsed = (new SourceConfigTransfer())->parse($yaml);
        self::assertSame([['source_path' => ['Water', 'Boards'], 'category' => ['Home', 'Sport', 'Boards']], ['source_path' => ['Misc'], 'category' => null]], $parsed['category_mappings']);
        self::assertSame([['source_name' => 'Duotone', 'manufacturer' => 'Duotone'], ['source_name' => 'Other', 'manufacturer' => null]], $parsed['brand_mappings']);

        $tilde = (new SourceConfigTransfer())->parse("format: productimport-source\nversion: 1\nsource: {}\ncategory_mappings:\n  Demo Fins: ~\nbrand_mappings:\n  Demo: ~\n", true);
        self::assertSame([['source_path' => ['Demo Fins'], 'category' => null]], $tilde['category_mappings']);
        self::assertNull($tilde['brand_mappings'][0]['manufacturer']);
    }

    public function testUnlistedFeedValuesAreCreatedAsPreMappedEntries(): void
    {
        $yaml = "format: productimport-source\nversion: 1\ncategory_mappings:\n  Windsurf > Sails: Home > Sport\n  Demo Fins: ~\nbrand_mappings:\n  Demo Watersports: Duotone\n";
        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolveOverwrite($transfer->parse($yaml, true), self::existing(), self::importLookups());
        self::assertSame([], $result['warnings']);
        self::assertSame([['source_path' => ['Windsurf', 'Sails'], 'id_category' => 5], ['source_path' => ['Demo Fins'], 'id_category' => null]], $result['category_mappings']);
        self::assertSame([['source_name' => 'Demo Watersports', 'id_manufacturer' => 9]], $result['brand_mappings']);
    }

    public function testMissingDestinationWarnsAndSkipsTheEntry(): void
    {
        $yaml = "format: productimport-source\nversion: 1\ncategory_mappings:\n  Feed Cat: Home > Nowhere\nbrand_mappings:\n  Feed Brand: No Such Brand\n";
        $transfer = new SourceConfigTransfer();
        $result = $transfer->resolveOverwrite($transfer->parse($yaml, true), self::existing(), self::importLookups());
        self::assertSame([], $result['category_mappings']);
        self::assertSame([], $result['brand_mappings']);
        self::assertCount(2, $result['warnings']);
        self::assertStringContainsString('Category mapping for "Feed Cat"', $result['warnings'][0]);
        self::assertStringContainsString('left unchanged', $result['warnings'][0]);
        self::assertStringContainsString('Brand mapping for "Feed Brand"', $result['warnings'][1]);
        self::assertStringContainsString('left unchanged', $result['warnings'][1]);
    }

    public function testDuplicateKeysAreSurfaced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a key appears twice');
        (new SourceConfigTransfer())->parse("format: productimport-source\nversion: 1\nbrand_mappings:\n  Duotone: A\n  Duotone: B\n", true);
    }

    public function testCategoryNameContainingTheSeparatorCannotBeExported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be written in the YAML mappings');
        (new SourceConfigTransfer())->build(self::source(), [['source_path' => '["A > B"]', 'id_category' => null]], [], self::lookups(), '0.15.0', 'now');
    }

    public function testDestinationCategoryWithSeparatorInsideANameCannotBeExported(): void
    {
        $lookups = self::lookups();
        $lookups['category_path'] = static function () {
            return ['Home', 'A > B'];
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be written in the YAML mappings');
        (new SourceConfigTransfer())->build(self::source(), [['source_path' => '["X"]', 'id_category' => '6']], [], $lookups, '0.15.0', 'now');
    }

    public function testLocationInYamlForUrlAndFilePathAndOverwrite(): void
    {
        $transfer = new SourceConfigTransfer();
        $parsed = $transfer->parse(self::export());
        self::assertSame('https://example.com/feed.json', $parsed['source']['location']);
        $document = Yaml::parse(self::export());
        $document['source']['location'] = 'feeds/shop.json';
        $parsed = $transfer->parse(json_encode($document));
        self::assertSame('feeds/shop.json', $parsed['source']['location']);
        $result = $transfer->resolveOverwrite($transfer->parse("format: productimport-source\nversion: 1\nsource:\n  location: /srv/feeds/new.json\n", true), self::existing(), self::importLookups());
        self::assertSame(['location' => '/srv/feeds/new.json'], $result['source']);
    }

    public function testCreateNewKeepsEverySourceSetting(): void
    {
        $yaml = "format: productimport-source\nversion: 1\nsource:\n  name: Xml shop\n  technical_key: xml_shop\n  language: sl\n  active: false\n  deactivate_missing: true\n"
            . "  source_format: xml\n  location: products.xml\n  xml_item_path: products/product\n  identifier_field: sku\n  filter_expression: fields.a\n"
            . "  root_category: Home > Sport\n  default_category: Home > Sport > Boards\n  default_manufacturer: Duotone\n  supplier: Acme\n"
            . "  field_mapping:\n    name: fields.n\n";
        $transfer = new SourceConfigTransfer();
        $source = $transfer->resolve($transfer->parse($yaml), self::importLookups(), static function () {
            return false;
        })['source'];
        self::assertSame(['xml', 'products.xml', 'products/product', 'sku', 'fields.a'], [$source['source_format'], $source['location'], $source['xml_item_path'], $source['identifier_field'], $source['filter_expression']]);
        self::assertSame([2, 5, 6, 9, 8, 0, 1], [$source['id_lang_default'], $source['root_category_id'], $source['default_id_category'], $source['default_id_manufacturer'], $source['id_supplier'], $source['active'], $source['deactivate_missing']]);
        self::assertSame('{"name":"fields.n"}', $source['field_mapping']);
    }

    public function testDeleteMissingDaysRoundTripsDefaultsAndOverwrites(): void
    {
        $transfer = new SourceConfigTransfer();
        $document = $transfer->build(self::source(['delete_missing_days' => '45']), [], [], self::lookups(), '0.18.0', 'now');
        self::assertSame(45, $document['source']['delete_missing_days']);
        $parsed = $transfer->parse(SourceConfigTransfer::encode($document));
        self::assertSame(45, $parsed['source']['delete_missing_days']);
        self::assertSame(45, $transfer->resolve($parsed, self::importLookups(), static fn () => false)['source']['delete_missing_days']);

        $never = $transfer->build(self::source(['delete_missing_days' => '0']), [], [], self::lookups(), '0.18.0', 'now');
        self::assertNull($never['source']['delete_missing_days']);
        $reparsed = $transfer->parse(SourceConfigTransfer::encode($never));
        self::assertNull($transfer->resolve($reparsed, self::importLookups(), static fn () => false)['source']['delete_missing_days']);

        $absent = $transfer->parse(Yaml::dump(['format' => 'productimport-source', 'version' => 1, 'source' => array_diff_key($document['source'], ['delete_missing_days' => 1])]));
        self::assertSame(90, $transfer->resolve($absent, self::importLookups(), static fn () => false)['source']['delete_missing_days']);

        $result = $transfer->resolveOverwrite($transfer->parse("format: productimport-source\nversion: 1\nsource:\n  delete_missing_days: 10\n", true), self::existing(), self::importLookups());
        self::assertSame(['delete_missing_days' => 10], $result['source']);

        $this->expectException(\InvalidArgumentException::class);
        $transfer->parse("format: productimport-source\nversion: 1\nsource:\n  delete_missing_days: -1\n", true);
    }

    public function testExportQuotesMappingsAndRoundTrips(): void
    {
        $cats = [['source_path' => '["It\'s: a # b","X"]', 'id_category' => '6'], ['source_path' => '["2024"]', 'id_category' => null]];
        $brands = [['source_name' => 'Kid\'s: Co # 1', 'id_manufacturer' => '9'], ['source_name' => 'true', 'id_manufacturer' => null]];
        $document = (new SourceConfigTransfer())->build(self::source(), $cats, $brands, self::lookups(), '0.15.0', 'now');
        $yaml = SourceConfigTransfer::encode($document);
        self::assertStringContainsString("\n  'It''s: a # b > X': 'Home > Sport > Boards'\n", $yaml);
        self::assertStringContainsString("\n  '2024': ~\n", $yaml);
        self::assertStringContainsString("\n  'Kid''s: Co # 1': 'Duotone'\n", $yaml);
        self::assertStringContainsString("\n  'true': ~\n", $yaml);
        $parsed = (new SourceConfigTransfer())->parse($yaml);
        self::assertSame(['It\'s: a # b', 'X'], $parsed['category_mappings'][0]['source_path']);
        self::assertSame(['2024'], $parsed['category_mappings'][1]['source_path']);
        self::assertSame('Kid\'s: Co # 1', $parsed['brand_mappings'][0]['source_name']);
        self::assertSame('true', $parsed['brand_mappings'][1]['source_name']);
    }

    public function testNumericDestinationAndKeyGiveFriendlyErrors(): void
    {
        $transfer = new SourceConfigTransfer();
        try {
            $transfer->parse("format: productimport-source\nversion: 1\ncategory_mappings:\n  Kites > Trainer: 123\n", true);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('destination of "Kites > Trainer" was read as the number 123; put it in quotes: \'123\'', $e->getMessage());
        }
        try {
            $transfer->parse("format: productimport-source\nversion: 1\nbrand_mappings:\n  Acme: true\n", true);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('put it in quotes', $e->getMessage());
        }
        $parsed = $transfer->parse("format: productimport-source\nversion: 1\nbrand_mappings:\n  2024: Home\n", true);
        self::assertSame('2024', $parsed['brand_mappings'][0]['source_name']);
    }
}
