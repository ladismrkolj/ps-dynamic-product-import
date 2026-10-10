<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;
use ProductImport\Repository\SourceRepository;
use ProductImport\Service\{JsonFetcher, ProductFilter, ProductFieldMapper, CategoryPathNormalizer, CategoryResolver, ManufacturerResolver, ProductImporter, VariantFieldMapper, AttributeResolver, CombinationImporter, ImportCatalog, ImportRunner, ExpressionEvaluator, ImportedValues};

class ImportRunnerTest extends TestCase
{
    private function fixture(array $items, bool $fetchFails = false, ?object $sharedState = null): array
    {
        $state = $sharedState ?? (object) ['products' => ['old' => ['external_id' => 'old', 'id_product' => 10, 'id_run_last_seen' => null], 'stale' => ['external_id' => 'stale', 'id_product' => 11, 'id_run_last_seen' => 1]],
            'combinations' => ['old:stale' => ['id_product' => 10, 'id_product_attribute' => 50, 'id_run_last_seen' => null]],
            'triggers' => [], 'deactivated' => [], 'zeroed' => [], 'finished' => [], 'prices' => [], 'staleCalls' => 0, 'emptyStreakUpdates' => [],
            'activeInStore' => [], 'savedImportedValues' => [], 'imports' => [], 'missing' => []];
        $fetcher = new class ($items, $fetchFails) extends JsonFetcher {
            public function __construct(private array $items, private bool $fails)
            {
            }
            public function fetch(?string $url, ?string $filePath): array
            {
                if ($this->fails) {
                    throw new \RuntimeException('fetch failed');
                }
                return $this->items;
            }
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
                $this->state->products[$external] = ['external_id' => $external, 'id_product' => $product, 'id_run_last_seen' => $run] + ($this->state->products[$external] ?? []);
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
                return array_values(array_filter($this->state->products, static fn ($row) => $row['id_run_last_seen'] !== $run));
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
                if ($external === 'broken') {
                    throw new \RuntimeException('save failed');
                }
                TestCase::assertSame([3], $categories);
                TestCase::assertSame(4, $manufacturer);
                TestCase::assertNull($supplier);
                return $external === 'old' ? 10 : 12;
            }
        };
        $categories = new class () extends CategoryResolver {
            public function __construct()
            {
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
            public function resolve(?string $name, int $idSource, bool $commit = true, ?int $defaultId = null)
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
            public function import(int $source, string $parent, string $variant, int $product, float $price, array $fields, array $ids): int
            {
                $this->state->prices[] = $price;
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
            public function savedPrice(int $idProduct): float
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
            public function zeroCombination(int $idProduct, int $idCombination): void
            {
                $this->state->zeroed[] = [$idProduct, $idCombination];
            }
        };
        $evaluator = new ExpressionEvaluator();
        $runner = new ImportRunner($fetcher, new ProductFilter($evaluator), new ProductFieldMapper($evaluator), new CategoryPathNormalizer(), $categories, $manufacturers, $products, new VariantFieldMapper($evaluator), $attributes, $combinations, $links, $combinationLinks, $sources, $runs, $catalog, null, '1.0.0');
        return [$runner, $state];
    }

    private function source(array $overrides = []): array
    {
        return $overrides + ['id_source' => 1, 'default_id_category' => 23, 'default_id_manufacturer' => 24, 'active' => 1, 'identifier_field' => 'id', 'id_lang_default' => 1,
            'field_mapping' => ['name' => '"Board"', 'category_paths' => '["Boards"]'], 'filter_expression' => 'path(fields, "skip") != true', 'deactivate_missing' => false];
    }

    public function testMixedItemsAndCleanupOnlyStaleLinks(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'new'], ['id' => 'skip', 'skip' => true], [], ['id' => 'broken']]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true]), 'admin-manual');
        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 1, 'failed' => 2], $result['counts']);
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
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0], $result['counts']);
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

    public function testVariantsUseMappedPrice(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'price' => 2826.53, 'variants' => [['sku' => 'good']]]]);
        $source = $this->source(['variant_mapping' => ['variants_expression' => 'fields["variants"]', 'attributes' => [['name' => 'Size', 'expression' => '"M"']], 'fields' => ['reference' => 'variant["sku"]']]]);
        $source['field_mapping']['price'] = 'fields["price"]';
        $result = $runner->runOne($source, 'admin-manual');
        self::assertSame('completed', $result['status']);
        self::assertSame([2826.53], $state->prices);
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
        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0], $result['counts']);
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
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'skipped' => 0, 'failed' => 0], $second['counts']);
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

    public function testMissingProductReimportsDespiteMatchingHash(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old']]);
        $runner->runOne($this->source(), 'admin-manual');
        $state->missing[] = 10;
        $result = $runner->runOne($this->source(), 'admin-manual');
        self::assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0], $result['counts']);
        self::assertSame(['old', 'old'], $state->imports);
    }

    public function testFeedHashIgnoresKeyOrderButNotValues(): void
    {
        $source = $this->source();
        $a = ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 4, '1');
        self::assertSame($a, ImportRunner::feedHash(['b' => ['y' => 2, 'x' => 1], 'a' => 1], $source, [3], 4, '1'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 3]], $source, [3], 4, '1'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 5, '1'));
        self::assertNotSame($a, ImportRunner::feedHash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $source, [3], 4, '2'));
    }
}
