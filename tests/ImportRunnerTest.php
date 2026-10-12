<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;
use ProductImport\Repository\SourceRepository;
use ProductImport\Service\{ProductFilter, ProductFieldMapper, CategoryPathNormalizer, CategoryResolver, ManufacturerResolver, ProductImporter, VariantFieldMapper, AttributeResolver, CombinationImporter, ImportCatalog, ImportRunner, ExpressionEvaluator, ImportedValues, FeatureMapper, CarrierResolver};

require_once __DIR__ . '/FeatureFakes.php';

class ImportRunnerTest extends TestCase
{
    private function fixture(array $items, bool $fetchFails = false, ?object $sharedState = null): array
    {
        $state = $sharedState ?? (object) ['products' => ['old' => ['external_id' => 'old', 'id_product' => 10, 'id_run_last_seen' => null], 'stale' => ['external_id' => 'stale', 'id_product' => 11, 'id_run_last_seen' => 1]],
            'combinations' => ['old:stale' => ['id_product' => 10, 'id_product_attribute' => 50, 'id_run_last_seen' => null]],
            'triggers' => [], 'deactivated' => [], 'zeroed' => [], 'finished' => [], 'prices' => [], 'staleCalls' => 0, 'emptyStreakUpdates' => [],
            'activeInStore' => [], 'importValues' => [], 'variantFields' => [], 'references' => [], 'savedImportedValues' => [], 'imports' => [], 'missing' => [], 'related' => [], 'featureMappings' => new FakeFeatureMappings(), 'featureResolver' => new FakeFeatureResolver(), 'relatedFails' => [],
            'tombstoneCalls' => [], 'otherLinks' => [], 'deletedProducts' => [], 'forgotten' => []];
        $fetcher = static function (array $source) use ($items, $fetchFails): array {
            if ($fetchFails) {
                throw new \RuntimeException('fetch failed');
            }
            return $items;
        };
        $links = new class ($state) extends ExternalProductRepository {
            public function __construct(private object $state)
            {
            }
            public function findProductId(int $source, string $external): ?int
            {
                return $this->state->products[$external]['id_product'] ?? null;
            }
            public function link(int $source, string $external, int $product, ?int $run = null, ?array $importedValues = null): void
            {
                $this->state->products[$external] = ['external_id' => $external, 'id_product' => $product, 'id_run_last_seen' => $run, 'date_missing' => null] + ($this->state->products[$external] ?? []);
            }
            public function findFeedHash(int $source, string $external): ?string
            {
                return $this->state->products[$external]['feed_hash'] ?? null;
            }
            public function setFeedHash(int $source, string $external, ?string $hash): void
            {
                if (isset($this->state->products[$external])) {
                    $this->state->products[$external]['feed_hash'] = $hash;
                }
            }
            public function productExists(int $product): bool
            {
                return !in_array($product, $this->state->missing, true);
            }
            public function markSeen(int $source, string $external, int $product, int $run): void
            {
                $this->state->products[$external]['id_run_last_seen'] = $run;
                $this->state->products[$external]['date_missing'] = null;
                foreach ($this->state->combinations as $key => $row) {
                    if ($row['id_product'] === $product) {
                        $this->state->combinations[$key]['id_run_last_seen'] = $run;
                    }
                }
            }
            public function saveImportedValues(int $source, string $external, array $importedValues): void
            {
                $this->state->savedImportedValues[$external] = $importedValues;
            }
            public function findStaleForSource(int $source, int $run): array
            {
                ++$this->state->staleCalls;
                return array_values(array_filter($this->state->products, static fn ($row) => $row['id_run_last_seen'] !== $run && !isset($row['date_deleted'])));
            }
            public function findTombstonedExternalIds(int $source): array
            {
                return array_keys(array_filter($this->state->products, static fn ($row) => isset($row['date_deleted'])));
            }
            public function tombstoneProduct(int $product, ?string $name = null): int
            {
                $this->state->tombstoneCalls[] = $product;
                foreach ($this->state->products as $external => $row) {
                    if ($row['id_product'] === $product) {
                        $this->state->products[$external]['date_deleted'] = '2026-01-01 00:00:00';
                    }
                }
                return 1;
            }
            public function markMissing(int $source, string $external, string $date): void
            {
                $this->state->products[$external]['date_missing'] ??= $date;
            }
            public function hasLinkFromOtherSource(int $product, int $source, string $external): bool
            {
                return in_array($product, $this->state->otherLinks, true);
            }
            public function unlink(int $source, string $external): void
            {
                unset($this->state->products[$external]);
            }
            public function forgetProductDetails(int $product): void
            {
                $this->state->forgotten[] = $product;
            }
        };
        $combinationLinks = new class ($state) extends ExternalCombinationRepository {
            public function __construct(private object $state)
            {
            }
            public function link(int $source, string $external, int $combination, int $product, ?int $run = null, ?array $importedValues = null): void
            {
                $this->state->combinations[$external] = ['id_product' => $product, 'id_product_attribute' => $combination, 'id_run_last_seen' => $run];
            }
            public function findStaleForSource(int $source, int $run): array
            {
                ++$this->state->staleCalls;
                return array_values(array_filter($this->state->combinations, static fn ($row) => $row['id_run_last_seen'] !== $run));
            }
        };
        $runs = new class ($state) extends ImportRunRepository {
            public function __construct(private object $state)
            {
            }
            public function start(int $source, string $triggeredBy): int
            {
                $this->state->triggers[] = $triggeredBy;
                if ($source === 99) {
                    throw new \RuntimeException('start failed');
                }
                return 20;
            }
            public function finish(int $run, string $status, array $counts, string $log): void
            {
                $this->state->finished[] = compact('run', 'status', 'counts', 'log');
            }
        };
        $sources = new class ($state) extends SourceRepository {
            public function __construct(private object $state)
            {
            }
            public function setConsecutiveEmptyCount(int $idSource, int $count): void
            {
                $this->state->emptyStreakUpdates[] = [$idSource, $count];
            }
        };
        $products = new class ($state) extends ProductImporter {
            public function __construct(private object $state)
            {
            }
            public function import(int $source, string $external, array $values, array $categories, ?int $manufacturer, ?int $supplier, int $language): int
            {
                $this->state->imports[] = $external;
                $this->state->importValues[$external] = $values;
                if ($external === 'gone') {
                    // Like the real importer: the linked product no longer exists, so its link became a tombstone.
                    $this->state->tombstoneCalls[] = 99;
                    throw new \ProductImport\Service\ProductDeletedException('Linked product no longer exists: 99');
                }
                if ($external === 'broken') {
                    throw new \RuntimeException('save failed');
                }
                if ($external === 'orphan' && !isset($this->state->products['orphan'])) {
                    // Like the real importer: the link is written right after the first save, then a later step fails.
                    $this->state->products['orphan'] = ['external_id' => 'orphan', 'id_product' => 13, 'id_run_last_seen' => null];
                    throw new \RuntimeException('step after save failed');
                }
                TestCase::assertSame([3], $categories);
                TestCase::assertSame(4, $manufacturer);
                TestCase::assertNull($supplier);
                return $external === 'old' ? 10 : 12;
            }
            public function importRelated(int $source, string $external, int $product, array $relatedIds): bool
            {
                if (in_array($external, $this->state->relatedFails, true)) {
                    throw new \RuntimeException('related failed');
                }
                $this->state->related[$external] = [$product, $relatedIds];
                return true;
            }
        };
        $categories = new class ($state) extends CategoryResolver {
            public function __construct(private object $state)
            {
            }
            public function drainNotices(): array
            {
                $notices = $this->state->notices ?? [];
                $this->state->notices = [];
                return $notices;
            }
            public function resolve(array $paths, int $source, ?int $root, bool $commit = true, ?int $defaultId = null): array
            {
                TestCase::assertTrue($commit);
                TestCase::assertSame(23, $defaultId);
                TestCase::assertSame([['Boards']], $paths);
                return [3];
            }
        };
        $manufacturers = new class () extends ManufacturerResolver {
            public function resolve(?string $name, int $idSource, bool $commit = true, ?int $defaultId = null): int|array|null
            {
                TestCase::assertSame(24, $defaultId);
                TestCase::assertSame(1, $idSource);
                TestCase::assertTrue($commit);
                return 4;
            }
        };
        $attributes = new class () extends AttributeResolver {
            public function resolve(string $group, string $value, bool $commit = true): array
            {
                TestCase::assertTrue($commit);
                return ['id_attribute_group' => 5, 'id_attribute' => 6];
            }
        };
        $combinations = new class ($state) extends CombinationImporter {
            public function __construct(private object $state)
            {
            }
            public function import(int $source, string $parent, string $variant, int $product, float $price, array $fields, array $ids, ?int $idSupplier, int $idLang): int
            {
                $this->state->prices[] = $price;
                $this->state->variantFields[] = $fields;
                TestCase::assertSame([6], $ids);
                if ($variant === 'bad') {
                    throw new \RuntimeException('variant failed');
                }
                return 60;
            }
        };
        $catalog = new class ($state) extends ImportCatalog {
            public function __construct(private object $state)
            {
            }
            public function variantBasePrice(int $idProduct): float
            {
                return 123.5;
            }
            public function deactivateProduct(int $idProduct, ?bool $importedActive = null): bool
            {
                $active = $this->state->activeInStore[$idProduct] ?? true;
                if ($importedActive !== null && $active !== $importedActive) {
                    return false;
                }
                $this->state->deactivated[] = $idProduct;
                return true;
            }
            public function productIdByReference(string $reference, ?int $exceptProduct = null): ?int
            {
                $owner = $this->state->references[$reference] ?? null;
                return $owner !== null && $owner !== $exceptProduct ? $owner : null;
            }
            public function zeroCombination(int $idProduct, int $idCombination): void
            {
                $this->state->zeroed[] = [$idProduct, $idCombination];
            }
            public function isProductActive(int $idProduct): bool
            {
                return $this->state->activeInStore[$idProduct] ?? true;
            }
            public function deleteProduct(int $idProduct): void
            {
                if ($this->state->deleteFails ?? false) {
                    throw new \RuntimeException('delete failed');
                }
                $this->state->deletedProducts[] = $idProduct;
            }
        };
        $features = new FeatureMapper($state->featureMappings, $state->featureResolver, new ExpressionEvaluator());
        $carriers = new class () extends CarrierResolver {
            public function __construct()
            {
            }
            public function resolve(mixed $input): array
            {
                return CarrierResolver::match(\ProductImport\Service\NativeFields::stringList($input), [['id_reference' => 7, 'name' => 'DPD'], ['id_reference' => 9, 'name' => 'GLS']]);
            }
        };
        $evaluator = new ExpressionEvaluator();
        $runner = new ImportRunner($fetcher, new ProductFilter($evaluator), new ProductFieldMapper($evaluator), new CategoryPathNormalizer(), $categories, $manufacturers, $products, new VariantFieldMapper($evaluator), $attributes, $combinations, $links, $combinationLinks, $sources, $runs, $catalog, $features, $carriers, '1.0.0');
        return [$runner, $state];
    }

    private function source(array $overrides = []): array
    {
        return $overrides + ['id_source' => 1, 'default_id_category' => 23, 'default_id_manufacturer' => 24, 'active' => 1, 'identifier_field' => 'id', 'id_lang_default' => 1,
            'field_mapping' => ['name' => '"Board"', 'category_paths' => '["Boards"]'], 'filter_expression' => 'path(fields, "skip") != true', 'deactivate_missing' => false, 'consecutive_empty_count' => 0, 'source_format' => 'json'];
    }

    public function testRepairedMappingNoticesAreWrittenToTheRunLog(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new']]);
        $state->notices = ['category "Boards": mapped category #9 no longer exists, reconnected to #3'];
        $result = $runner->runOne($this->source(), 'admin-manual');
        self::assertStringContainsString('item 0: category "Boards": mapped category #9 no longer exists, reconnected to #3', $result['error_log']);
    }

    public function testMixedItemsAndCleanupOnlyStaleLinks(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'new'], ['id' => 'skip', 'skip' => true], [], ['id' => 'broken']]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 1, 'failed' => 2, 'deleted' => 0], $result['counts']);
        self::assertSame('completed_with_errors', $result['status']);
        self::assertStringContainsString('item 3: missing identifier_field value', $result['error_log']);
        self::assertStringContainsString('item 4: save failed', $result['error_log']);
        self::assertSame([11], $state->deactivated);
        self::assertSame([[10, 50]], $state->zeroed);
        self::assertSame(20, $state->products['old']['id_run_last_seen']);
        self::assertSame($result['counts'], $state->finished[0]['counts']);
        self::assertSame(['admin-manual'], $state->triggers);
    }

    public function testFetchFailureNeverCleansUp(): void
    {
        [$runner, $state] = $this->fixture([], true);
        $result = $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame('failed', $result['status']);
        self::assertSame('fetch failed', $result['error_log']);
        self::assertSame(0, $state->staleCalls);
        self::assertSame([], $state->deactivated);
        self::assertSame('failed', $state->finished[0]['status']);
    }

    public function testEmptyFeedWithDeactivateMissingSkipsCleanupFirstTime(): void
    {
        [$runner, $state] = $this->fixture([]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame('failed', $result['status']);
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0], $result['counts']);
        self::assertSame([], $state->deactivated);
        self::assertSame([], $state->zeroed);
        self::assertSame(0, $state->staleCalls);
        self::assertStringContainsString('skipping deactivation', $result['error_log']);
        self::assertSame([[1, 1]], $state->emptyStreakUpdates);
        self::assertSame('failed', $state->finished[0]['status']);
    }

    public function testEmptyFeedSecondConsecutiveRunProceedsWithCleanup(): void
    {
        [$runner, $state] = $this->fixture([]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'consecutive_empty_count' => 1]), 'admin-manual');
        self::assertSame('completed', $result['status']);
        self::assertSame([10, 11], $state->deactivated);
        self::assertSame([[10, 50]], $state->zeroed);
        self::assertSame(2, $state->staleCalls);
        self::assertStringContainsString('proceeding with deactivation', $result['error_log']);
        self::assertSame([[1, 2]], $state->emptyStreakUpdates);
    }

    public function testStaleCleanupKeepsManualReactivationAndRecordsImportDeactivation(): void
    {
        [$runner, $state] = $this->fixture([]);
        $fingerprint = static fn (string $active) => ImportedValues::fingerprint(['active' => $active]);
        // 10 was deactivated by an earlier run, then reactivated by hand; 11 is still as the import left it.
        $state->products['old']['imported_values'] = ImportedValues::encode($fingerprint('0'));
        $state->products['stale']['imported_values'] = ImportedValues::encode($fingerprint('1') + ['name.1' => 'x']);
        $state->activeInStore = [10 => true, 11 => true];
        $runner->runOne($this->source(['deactivate_missing' => true, 'consecutive_empty_count' => 1]), 'admin-manual');
        self::assertSame([11], $state->deactivated);
        self::assertSame(['stale' => $fingerprint('0') + ['name.1' => 'x']], $state->savedImportedValues);

        $state->activeInStore[11] = false;
        $state->savedImportedValues = [];
        $state->products['stale']['imported_values'] = ImportedValues::encode($fingerprint('0'));
        $runner->runOne($this->source(['deactivate_missing' => true, 'consecutive_empty_count' => 2]), 'admin-manual');
        self::assertSame([], $state->savedImportedValues, 'an already recorded deactivation is not rewritten');
    }

    public function testNonEmptyRunResetsEmptyStreak(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'consecutive_empty_count' => 2]), 'admin-manual');
        self::assertSame('completed', $result['status']);
        self::assertSame([[1, 0]], $state->emptyStreakUpdates);
    }

    public function testVariantsUseTheCatalogBasePriceNotTheFeedPrice(): void
    {
        // The base is what the store will add impacts to (sale price when discounted), not the mapped product price.
        [$runner, $state] = $this->fixture([['id' => 'old', 'price' => 2826.53, 'variants' => [['sku' => 'good']]]]);
        $source = $this->source(['variant_mapping' => ['variants_expression' => 'fields["variants"]', 'attributes' => [['name' => 'Size', 'expression' => '"M"']], 'fields' => ['reference' => 'variant["sku"]']]]);
        $source['field_mapping']['price'] = 'fields["price"]';
        $result = $runner->runOne($source, 'admin-manual');
        self::assertSame('completed', $result['status']);
        self::assertSame([123.5], $state->prices);
    }

    public function testVariantsUseSavedPriceWhenPriceIsNotMappedAndContinueAfterFailure(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'variants' => [['sku' => 'bad'], ['sku' => 'good']]]]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'variant_mapping' => ['variants_expression' => 'fields["variants"]', 'attributes' => [['name' => 'Size', 'expression' => '"M"']], 'fields' => ['reference' => 'variant["sku"]']]]), 'admin-manual');
        self::assertSame(1, $result['counts']['updated']);
        self::assertSame(1, $result['counts']['failed']);
        self::assertSame([123.5, 123.5], $state->prices);
        self::assertSame(20, $state->combinations['old:good']['id_run_last_seen']);
        self::assertSame([[10, 50]], $state->zeroed);
    }

    public function testProductsWithoutVariantsDoNotFail(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'variants' => null], ['id' => 'new']]);
        $result = $runner->runOne($this->source(['variant_mapping' => ['variants_expression' => 'path(fields, "variants")', 'attributes' => [], 'fields' => []]]), 'admin-manual');
        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0], $result['counts']);
        self::assertSame('completed', $result['status']);
        self::assertSame('', $result['error_log']);
        self::assertSame([], $state->prices);
    }

    public function testBrokenFilterIsIsolatedPerItem(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'new']]);
        $result = $runner->runOne($this->source(['filter_expression' => 'fields[']), 'admin-manual');
        self::assertSame(2, $result['counts']['failed']);
        self::assertSame(0, $result['counts']['created']);
        self::assertNull($state->products['old']['id_run_last_seen']);
    }

    public function testFieldWarningsDoNotPreventImportAndLogIsBounded(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new']]);
        $source = $this->source();
        $source['field_mapping']['bad'] = 'fields[';
        $result = $runner->runOne($source, 'admin-manual');
        self::assertSame(1, $result['counts']['created']);
        self::assertStringContainsString('field bad', $result['error_log']);
        self::assertSame(0, $state->staleCalls);
        $log = ImportRunRepository::capLog(str_repeat('ž', 50000));
        self::assertLessThanOrEqual(65536, strlen($log));
        self::assertStringEndsWith('[log truncated]', $log);
        self::assertTrue(mb_check_encoding($log, 'UTF-8'));
    }

    public function testRunAllSkipsInactiveAndSurvivesStartFailure(): void
    {
        [$runner, $state] = $this->fixture([]);
        $results = $runner->runAll([$this->source(['active' => 0]), $this->source(['id_source' => 99]), $this->source()], 'cron-background');
        self::assertCount(2, $results);
        self::assertSame('failed', $results[0]['status']);
        self::assertSame('completed', $results[1]['status']);
        self::assertCount(1, $state->finished);
        self::assertSame(['cron-background', 'cron-background'], $state->triggers);
    }

    private function variantSource(): array
    {
        return $this->source(['variant_mapping' => ['variants_expression' => 'fields["variants"]', 'attributes' => [['name' => 'Size', 'expression' => '"M"']], 'fields' => ['reference' => 'variant["sku"]']]]);
    }

    public function testIdenticalSecondRunCountsUnchangedAndCallsNoImporter(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'variants' => [['sku' => 'good']]]]);
        $source = $this->variantSource();
        $first = $runner->runOne($source, 'admin-manual');
        self::assertSame(1, $first['counts']['updated']);
        self::assertSame(['old'], $state->imports);
        self::assertNotNull($state->products['old']['feed_hash']);
        $state->products['old']['id_run_last_seen'] = null;
        $state->combinations['old:good']['id_run_last_seen'] = null;

        $second = $runner->runOne($source, 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'skipped' => 0, 'failed' => 0, 'deleted' => 0], $second['counts']);
        self::assertSame(['old'], $state->imports, 'no importer call on the unchanged run');
        self::assertSame([123.5], $state->prices);
        self::assertSame(20, $state->products['old']['id_run_last_seen']);
        self::assertSame(20, $state->combinations['old:good']['id_run_last_seen']);
    }

    public function testChangedItemOrMappingReimports(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'quantity' => 1]]);
        $runner->runOne($this->source(), 'admin-manual');
        $hash = $state->products['old']['feed_hash'];
        [$changed] = $this->fixture([['id' => 'old', 'quantity' => 2]], false, $state);
        $result = $changed->runOne($this->source(), 'admin-manual');
        self::assertSame(1, $result['counts']['updated']);
        self::assertNotSame($hash, $state->products['old']['feed_hash']);

        $result = $changed->runOne($this->source(['field_mapping' => ['name' => '"Other"', 'category_paths' => '["Boards"]']]), 'admin-manual');
        self::assertSame(1, $result['counts']['updated']);
        self::assertSame(['old', 'old', 'old'], $state->imports);
    }

    public function testFailedVariantLeavesHashNullAndNextRunRetries(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'variants' => [['sku' => 'bad']]]]);
        $result = $runner->runOne($this->variantSource(), 'admin-manual');
        self::assertSame(1, $result['counts']['failed']);
        self::assertNull($state->products['old']['feed_hash']);
        $again = $runner->runOne($this->variantSource(), 'admin-manual');
        self::assertSame(0, $again['counts']['unchanged']);
        self::assertSame(['old', 'old'], $state->imports);
    }

    public function testStaleDeactivationClearsHash(): void
    {
        [$runner, $state] = $this->fixture([]);
        $state->products['stale']['feed_hash'] = str_repeat('a', 32);
        $runner->runOne($this->source(['deactivate_missing' => true, 'consecutive_empty_count' => 1]), 'admin-manual');
        self::assertContains(11, $state->deactivated);
        self::assertNull($state->products['stale']['feed_hash']);
    }

    public function testLinkedProductDeletedWithoutTombstoneBecomesTombstoneAndIsSkipped(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $runner->runOne($this->source(), 'admin-manual');
        $state->missing[] = 10;
        $result = $runner->runOne($this->source(), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1, 'failed' => 0, 'deleted' => 0], $result['counts']);
        self::assertSame([10], $state->tombstoneCalls);
        self::assertSame(['old'], $state->imports, 'no importer call for the tombstoned item');
        self::assertSame('completed', $result['status']);
        $again = $runner->runOne($this->source(), 'admin-manual');
        self::assertSame(1, $again['counts']['skipped']);
        self::assertSame(['old'], $state->imports);
    }

    public function testTombstonedItemsAreSkippedAndSummarisedOnce(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'stale'], ['id' => 'new']]);
        $state->products['old']['date_deleted'] = '2026-01-01 00:00:00';
        $state->products['stale']['date_deleted'] = '2026-01-01 00:00:00';
        $result = $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 2, 'failed' => 0, 'deleted' => 0], $result['counts']);
        self::assertSame(['new'], $state->imports);
        self::assertSame([], $state->deactivated, 'tombstones are ignored by stale cleanup');
        self::assertSame(1, substr_count($result['error_log'], 'skipped: their product was deleted'));
        self::assertStringContainsString('2 item(s) skipped', $result['error_log']);
        self::assertSame('completed', $result['status']);
    }

    public function testStaleLinkWhoseProductIsGoneBecomesTombstoneInsteadOfFailing(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $state->missing[] = 11;
        $result = $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame([11], $state->tombstoneCalls);
        self::assertSame([], $state->deactivated);
        self::assertSame(0, $result['counts']['failed']);
    }

    public function testFirstMissStartsTheClockAndKeepsItAfterwards(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        $first = $state->products['stale']['date_missing'];
        self::assertNotNull($first);
        self::assertSame([11], $state->deactivated);
        self::assertSame([], $state->deletedProducts, 'a fresh miss is never deleted');
        $state->products['stale']['date_missing'] = '2020-01-01 00:00:00';
        $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame('2020-01-01 00:00:00', $state->products['stale']['date_missing']);
    }

    private function expiredStale(object $state, int $daysAgo = 31): void
    {
        $state->products['stale']['date_missing'] = date('Y-m-d H:i:s', time() - $daysAgo * 86400);
        $state->activeInStore[11] = false;
    }

    public function testExpiredInactiveProductIsDeletedWithItsLinks(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $this->expiredStale($state);
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame([11], $state->deletedProducts);
        self::assertSame([11], $state->forgotten);
        self::assertArrayNotHasKey('stale', $state->products);
        self::assertSame(1, $result['counts']['deleted']);
        self::assertSame(0, $result['counts']['failed']);
        self::assertSame([], $state->deactivated);
        self::assertStringContainsString('Deleted product 11 (external id stale)', $result['error_log']);
        self::assertSame($result['counts'], $state->finished[0]['counts']);
    }

    public function testProductIsNotDeletedWhenStillActiveLinkedElsewhereRecentOrDisabled(): void
    {
        foreach (['active', 'other source', 'recent', 'never', 'zero'] as $case) {
            [$runner, $state] = $this->fixture([['id' => 'old']]);
            $this->expiredStale($state, $case === 'recent' ? 29 : 31);
            $state->activeInStore[11] = $case !== 'active' ? false : true;
            $state->otherLinks = $case === 'other source' ? [11] : [];
            $days = match ($case) {
                'never' => null,
                'zero' => 0,
                default => 30,
            };
            $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => $days]), 'admin-manual');
            self::assertSame([], $state->deletedProducts, $case);
            self::assertSame(0, $result['counts']['deleted'], $case);
            self::assertArrayHasKey('stale', $state->products, $case);
        }
    }

    public function testNothingIsDeletedWhenDeactivateMissingIsOff(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $this->expiredStale($state);
        $runner->runOne($this->source(['deactivate_missing' => false, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame([], $state->deletedProducts);
        self::assertSame(0, $state->staleCalls);
    }

    public function testEmptyFeedGuardAlsoGuardsDeletion(): void
    {
        [$runner, $state] = $this->fixture([]);
        $this->expiredStale($state);
        $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame([], $state->deletedProducts);
        self::assertSame(0, $state->staleCalls);
    }

    public function testPresentItemThatIsSkippedForItsTombstoneIsNeitherTimedNorDeleted(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'gone']]);
        $expired = date('Y-m-d H:i:s', time() - 400 * 86400);
        $state->products['gone'] = ['external_id' => 'gone', 'id_product' => 99, 'id_run_last_seen' => 1, 'date_missing' => $expired];
        $state->activeInStore[99] = false;
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1, 'failed' => 0, 'deleted' => 0], $result['counts']);
        self::assertSame('completed', $result['status']);
        self::assertSame([99], $state->tombstoneCalls);
        self::assertSame([], $state->deletedProducts);
        self::assertSame($expired, $state->products['gone']['date_missing']);
    }

    public function testPresentFailingItemKeepsItsProductAndStartsNoClock(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'broken']]);
        $state->products['broken'] = ['external_id' => 'broken', 'id_product' => 14, 'id_run_last_seen' => 1, 'date_missing' => null];
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame(1, $result['counts']['failed']);
        self::assertNull($state->products['broken']['date_missing'], 'a failing item is present in the feed, not missing');
        self::assertArrayHasKey('broken', $state->products);
        self::assertSame([], $state->deletedProducts);
        self::assertContains(14, $state->deactivated, 'it is still hidden as today');
    }

    public function testRunWithFailuresNeverDeletesAnything(): void
    {
        $feeds = ['failing item' => [['id' => 'old'], ['id' => 'broken']], 'item without identifier' => [['id' => 'old'], []]];
        foreach ($feeds as $case => $feed) {
            [$runner, $state] = $this->fixture($feed);
            $this->expiredStale($state);
            $expired = $state->products['stale']['date_missing'];
            $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
            self::assertSame([], $state->deletedProducts, $case);
            self::assertSame(0, $result['counts']['deleted'], $case);
            self::assertArrayHasKey('stale', $state->products, $case);
            self::assertSame($expired, $state->products['stale']['date_missing'], $case);
            self::assertSame(1, substr_count($result['error_log'], 'Auto-delete skipped'), $case);
            self::assertContains(11, $state->deactivated, $case);
        }
    }

    public function testFilteredOutItemWithExpiredDateIsDeleted(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'stale', 'skip' => true]]);
        $this->expiredStale($state);
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0, 'skipped' => 1, 'failed' => 0, 'deleted' => 1], $result['counts']);
        self::assertSame([11], $state->deletedProducts);
    }

    public function testReturningItemClearsDateMissing(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'stale']]);
        $state->products['stale']['date_missing'] = '2026-01-01 00:00:00';
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame(0, $result['counts']['failed']);
        self::assertNull($state->products['stale']['date_missing']);
        self::assertSame([], $state->deletedProducts);

        $state->products['old']['date_missing'] = '2026-01-01 00:00:00';
        $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertNull($state->products['old']['date_missing'], 'an unchanged item is marked seen and clears it too');
    }

    public function testFailedProductDeleteKeepsTheLinkSoItIsNotTombstonedLater(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $this->expiredStale($state);
        $state->deleteFails = true;
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'delete_missing_days' => 30]), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'failed' => 1, 'deleted' => 0], $result['counts']);
        self::assertSame(11, $state->products['stale']['id_product']);
        self::assertSame([], $state->tombstoneCalls);
    }

    public function testFeedHashIgnoresKeyOrderButNotValues(): void
    {
        $source = $this->source();
        $a = ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 4, null, '1');
        self::assertSame($a, ImportRunner::feedHash(['b' => ['y' => 2, 'x' => 1], 'a' => 1], $source, [3], 4, null, '1'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 3]], $source, [3], 4, null, '1'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 5, null, '1'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 4, null, '2'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 4, [['id_feature' => 5, 'id_feature_value' => 7]], '1'));
    }

    public function testInvalidEan13IsDroppedAndLogged(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new', 'ean' => '4006381333932'], ['id' => 'old', 'ean' => '4006381333931'], ['id' => 'x2', 'ean' => null]]);
        $source = $this->source(['field_mapping' => ['name' => '"Board"', 'category_paths' => '["Boards"]', 'ean13' => 'fields["ean"]']]);
        $result = $runner->runOne($source, 'admin-manual');
        self::assertArrayNotHasKey('ean13', $state->importValues['new']);
        self::assertSame('4006381333931', $state->importValues['old']['ean13']);
        self::assertSame('invalid EAN-13 "4006381333932" (check digit) — not written', trim(explode('field ean13: ', $result['error_log'])[1]));
        self::assertSame(1, substr_count($result['error_log'], 'ean13'));
    }

    public function testInvalidVariantEan13IsDropped(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'variants' => [['sku' => 'a', 'ean' => '12345678905'], ['sku' => 'b', 'ean' => '4006381333931']]]]);
        $result = $runner->runOne($this->source(['variant_mapping' => ['variants_expression' => 'fields["variants"]', 'attributes' => [['name' => 'Size', 'expression' => '"M"']], 'fields' => ['reference' => 'variant["sku"]', 'ean13' => 'variant["ean"]']]]), 'admin-manual');
        self::assertArrayNotHasKey('ean13', $state->variantFields[0]);
        self::assertSame('4006381333931', $state->variantFields[1]['ean13']);
        self::assertStringContainsString('item 0 variant 0 field ean13: invalid EAN-13 "12345678905" (length 11) — not written', $result['error_log']);
    }

    public function testReferenceUsedByAnotherProductIsNotWritten(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new', 'ref' => 'SKU-1'], ['id' => 'old', 'ref' => 'SKU-2']]);
        $state->references = ['SKU-1' => 99, 'SKU-2' => 10];
        $source = $this->source(['field_mapping' => ['name' => '"Board"', 'category_paths' => '["Boards"]', 'reference' => 'fields["ref"]']]);
        $result = $runner->runOne($source, 'admin-manual');
        self::assertArrayNotHasKey('reference', $state->importValues['new']);
        self::assertSame('SKU-2', $state->importValues['old']['reference']);
        self::assertStringContainsString('item 0 field reference: "SKU-1" is already used by product #99 — not written', $result['error_log']);
        self::assertSame(1, substr_count($result['error_log'], 'field reference'));
    }

    private function nativeSource(array $mapping): array
    {
        return $this->source(['unmapped_features' => 'create', 'field_mapping' => ['name' => '"Board"', 'category_paths' => '["Boards"]'] + $mapping]);
    }

    public function testNativeFieldsAreNormalisedAndResolved(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new', 'props' => [['n' => 'Color', 'v' => 'Red'], ['n' => 'Empty', 'v' => ''], ['n' => 'Bad', 'v' => 'bad<value>']], 'title' => str_repeat('é', 300)]]);
        $result = $runner->runOne($this->nativeSource([
            'features' => 'pairs(path(fields, "props"), "n", "v")',
            'carriers' => '["DPD", "nope", "9"]',
            'delivery_time' => '"Custom"',
            'meta_title' => 'fields["title"]',
            'additional_shipping_cost' => '-1',
        ]), 'admin-manual');
        $values = $state->importValues['new'];
        self::assertSame([['id_feature' => 101, 'id_feature_value' => 1001]], $values['features']);
        self::assertSame([['Color', 'Red', 1]], $state->featureResolver->calls);
        self::assertSame([7, 9], $values['carriers']);
        self::assertSame(2, $values['delivery_time']);
        self::assertSame(255, mb_strlen($values['meta_title']));
        self::assertArrayNotHasKey('additional_shipping_cost', $values);
        self::assertStringContainsString('item 0 field carriers: "nope" not found', $result['error_log']);
        self::assertStringContainsString('item 0 field meta_title: truncated to 255 characters', $result['error_log']);
        self::assertStringContainsString('item 0 field features: "Bad": feature value contains characters PrestaShop rejects', $result['error_log']);
        self::assertStringContainsString('item 0 field additional_shipping_cost: expected a number of at least 0', $result['error_log']);
    }

    private function featureItem(array $props): array
    {
        return ['id' => 'new', 'props' => array_map(static fn (string $name, string $value): array => ['n' => $name, 'v' => $value], array_keys($props), array_values($props))];
    }

    private function featureSource(array $overrides = []): array
    {
        return $overrides + $this->nativeSource(['features' => 'pairs(path(fields, "props"), "n", "v")']);
    }

    public function testFeatureMappingRulesDecideWhatIsImportedAndCreatedFeaturesAreConnected(): void
    {
        [$runner, $state] = $this->fixture([$this->featureItem(['Finbox' => 'US-BOX', 'Colour' => 'Red', 'Weight (kg)' => 'TBA', 'Discipline' => 'Freeride', 'Size' => 'M'])]);
        $rows = &$state->featureMappings->rows;
        $rows['Finbox'] = FakeFeatureMappings::row('Finbox', 'create', null, 'Fin box');
        $rows['Colour'] = FakeFeatureMappings::row('Colour', 'ignore');
        $rows['Weight (kg)'] = FakeFeatureMappings::row('Weight (kg)', 'create', null, 'Weight', "value == 'TBA' ? null : value");
        $rows['Size'] = FakeFeatureMappings::row('Size', 'map', 6);
        $result = $runner->runOne($this->featureSource(['unmapped_features' => 'ignore']), 'admin-manual');
        self::assertSame('completed', $result['status'], $result['error_log']);
        self::assertSame([['Fin box', 'US-BOX', 1], [6, 'M', 1]], $state->featureResolver->calls);
        self::assertSame([['id_feature' => 5, 'id_feature_value' => 1001], ['id_feature' => 6, 'id_feature_value' => 1002]], $state->importValues['new']['features']);
        self::assertSame('map', $rows['Finbox']['action'], 'the created feature is connected');
        self::assertSame(5, $rows['Finbox']['id_feature']);
        self::assertNull($rows['Finbox']['create_name']);
        self::assertSame('create', $rows['Weight (kg)']['action'], 'a skipped value connects nothing');
        self::assertSame('none', $rows['Discipline']['action'], 'an unseen name becomes a row without a mapping');
        self::assertContains('seen:Discipline', $state->featureMappings->writes);
        self::assertStringNotContainsString('field features', $result['error_log']);
    }

    public function testUnmappedFeatureNamesFollowTheSourceSetting(): void
    {
        [$runner, $state] = $this->fixture([$this->featureItem(['Discipline' => 'Freeride'])]);
        $runner->runOne($this->featureSource(['unmapped_features' => 'ignore']), 'admin-manual');
        self::assertSame([], $state->importValues['new']['features'], 'every name ignored: the product gets no features');
        self::assertSame([], $state->featureResolver->calls);
        [$runner, $state] = $this->fixture([$this->featureItem(['Discipline' => 'Freeride'])]);
        $runner->runOne($this->featureSource(['unmapped_features' => 'create']), 'admin-manual');
        self::assertSame([['Discipline', 'Freeride', 1]], $state->featureResolver->calls);
        self::assertSame('none', $state->featureMappings->rows['Discipline']['action'], 'the setting does not connect the name');
    }

    public function testTwoFeedNamesForOneFeatureKeepTheFirstAndLogOnce(): void
    {
        [$runner, $state] = $this->fixture([$this->featureItem(['Finbox' => 'US-BOX', 'Fin Box' => 'US-Box', 'Fin box' => 'Slot'])]);
        foreach (['Finbox', 'Fin Box', 'Fin box'] as $name) {
            $state->featureMappings->rows[$name] = FakeFeatureMappings::row($name, 'create', null, 'Fin box');
        }
        $result = $runner->runOne($this->featureSource(), 'admin-manual');
        self::assertSame([['id_feature' => 5, 'id_feature_value' => 1001]], $state->importValues['new']['features']);
        self::assertSame(1, substr_count($result['error_log'], 'item 0 field features: several feed names map to one feature'));
        self::assertStringContainsString('"Finbox" and "Fin Box" both map to the feature "Fin box"; "Finbox" is used', $result['error_log']);
        self::assertStringContainsString('"Finbox" and "Fin box" both map', $result['error_log']);
    }

    public function testMappedFeatureThatNoLongerExistsFallsBackToTheSetting(): void
    {
        [$runner, $state] = $this->fixture([$this->featureItem(['Size' => 'M'])]);
        $state->featureMappings->rows['Size'] = FakeFeatureMappings::row('Size', 'map', 99);
        $result = $runner->runOne($this->featureSource(['unmapped_features' => 'ignore']), 'admin-manual');
        self::assertSame([], $state->importValues['new']['features']);
        self::assertSame('none', $state->featureMappings->rows['Size']['action']);
        self::assertStringContainsString('item 0 field features: "Size": mapped feature #99 no longer exists, mapping cleared', $result['error_log']);
    }

    public function testValueFormulaErrorsAreLoggedPerItemAndSkipOnlyThatFeature(): void
    {
        [$runner, $state] = $this->fixture([$this->featureItem(['Colour' => 'Red', 'Size' => 'M'])]);
        $state->featureMappings->rows['Colour'] = FakeFeatureMappings::row('Colour', 'create', null, 'Colour', 'fields[');
        $state->featureMappings->rows['Size'] = FakeFeatureMappings::row('Size', 'create', null, 'Size', 'upper(value)');
        $result = $runner->runOne($this->featureSource(), 'admin-manual');
        self::assertSame([['Size', 'M', 1]], array_map(static fn (array $call): array => [$call[0], $call[1], $call[2]], $state->featureResolver->calls));
        self::assertStringContainsString('item 0 field features: value formula of Colour:', $result['error_log']);
    }

    public function testChangingAFeatureMappingReimportsAnUnchangedItem(): void
    {
        $items = [$this->featureItem(['Colour' => 'Red', 'Size' => 'M'])];
        [$runner, $state] = $this->fixture($items);
        $source = $this->featureSource(['unmapped_features' => 'create']);
        self::assertSame(1, $runner->runOne($source, 'admin-manual')['counts']['created']);
        [$again] = $this->fixture($items, false, $state);
        self::assertSame(1, $again->runOne($source, 'admin-manual')['counts']['unchanged']);
        $state->featureMappings->rows['Colour'] = FakeFeatureMappings::row('Colour', 'ignore');
        [$third] = $this->fixture($items, false, $state);
        $result = $third->runOne($source, 'admin-manual');
        self::assertSame(0, $result['counts']['unchanged']);
        self::assertSame(1, $result['counts']['updated']);
    }

    public function testCarriersOnlyUnknownAreNotWrittenButEmptyListMeansAll(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new']]);
        $runner->runOne($this->nativeSource(['carriers' => '["nope"]']), 'admin-manual');
        self::assertArrayNotHasKey('carriers', $state->importValues['new']);
        [$runner, $state] = $this->fixture([['id' => 'new']]);
        $runner->runOne($this->nativeSource(['carriers' => '[]']), 'admin-manual');
        self::assertSame([], $state->importValues['new']['carriers']);
    }

    public function testInvalidDeliveryTimeIsLoggedAndDropped(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new']]);
        $result = $runner->runOne($this->nativeSource(['delivery_time' => '"soon"']), 'admin-manual');
        self::assertArrayNotHasKey('delivery_time', $state->importValues['new']);
        self::assertStringContainsString('item 0 field delivery_time: delivery_time must be', $result['error_log']);
    }

    public function testRelatedProductsResolveAfterTheLoopFeedIdBeforeReferenceAndSelfExcluded(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new', 'rel' => ['old', 'SKU-9', 'ghost', 'new', 'dup']]]);
        $state->references = ['SKU-9' => 77, 'old' => 99, 'dup' => 12];
        $result = $runner->runOne($this->nativeSource(['related_products' => 'fields["rel"]']), 'admin-manual');
        self::assertArrayNotHasKey('related_products', $state->importValues['new']);
        // 'old' is a feed ID (10), not the reference owner 99; 'new' and the reference of product 12 are the product itself.
        self::assertSame(['new' => [12, [10, 77]]], $state->related);
        self::assertStringContainsString('item 0 field related_products: "ghost" not found', $result['error_log']);
        self::assertSame(1, substr_count($result['error_log'], 'not found'));
    }

    public function testRelatedWithNothingFoundIsNotAppliedButEmptyListClears(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new', 'rel' => ['ghost']]]);
        $runner->runOne($this->nativeSource(['related_products' => 'fields["rel"]']), 'admin-manual');
        self::assertSame([], $state->related);
        [$runner, $state] = $this->fixture([['id' => 'new', 'rel' => []]]);
        $runner->runOne($this->nativeSource(['related_products' => 'fields["rel"]']), 'admin-manual');
        self::assertSame(['new' => [12, []]], $state->related);
    }

    public function testRelatedErrorIsPerProductAndRunCompletes(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new', 'rel' => ['old']], ['id' => 'old', 'rel' => ['new']]]);
        $state->relatedFails = ['new'];
        $result = $runner->runOne($this->nativeSource(['related_products' => 'fields["rel"]']), 'admin-manual');
        self::assertSame(1, $result['counts']['failed']);
        self::assertSame('completed_with_errors', $result['status']);
        self::assertSame(['old' => [10, [12]]], $state->related);
        self::assertStringContainsString('item 0 field related_products: related failed', $result['error_log']);
    }

    public function testFailureAfterFirstSaveLeavesLinkSoNextRunUpdatesInsteadOfDuplicating(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'orphan']]);
        $first = $runner->runOne($this->source(), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 1, 'deleted' => 0], $first['counts']);
        self::assertSame(13, $state->products['orphan']['id_product']);
        self::assertNull($state->products['orphan']['feed_hash'] ?? null);
        $second = $runner->runOne($this->source(), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0], $second['counts']);
    }
}
