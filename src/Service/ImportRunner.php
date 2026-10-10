<?php

namespace ProductImport\Service;

use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;
use ProductImport\Repository\SourceRepository;

class ImportRunner
{
    public function __construct(
        private JsonFetcher $fetcher,
        private ProductFilter $filter,
        private ProductFieldMapper $mapper,
        private CategoryPathNormalizer $normalizer,
        private CategoryResolver $categories,
        private ManufacturerResolver $manufacturers,
        private ProductImporter $products,
        private VariantFieldMapper $variants,
        private AttributeResolver $attributes,
        private CombinationImporter $combinations,
        private ExternalProductRepository $productLinks,
        private ExternalCombinationRepository $combinationLinks,
        private SourceRepository $sourceRepository,
        private ImportRunRepository $runs,
        private ImportCatalog $catalog,
        private ?XmlFetcher $xmlFetcher = null,
        private ?string $moduleVersion = null
    ) {
    }

    /** Source mapping columns must be decoded by the caller, as for PreviewBuilder. */
    public function runOne(array $source, string $triggeredBy): array
    {
        $idSource = (int) $source['id_source'];
        $idRun = $this->runs->start($idSource, $triggeredBy);
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $log = '';
        try {
            $items = FeedFetcher::fetch($source, $this->fetcher, $this->xmlFetcher);
        } catch (\Throwable $error) {
            $log = ImportRunRepository::capLog($error->getMessage());
            $this->runs->finish($idRun, 'failed', $counts, $log);
            return ['id_source' => $idSource, 'id_run' => $idRun, 'status' => 'failed', 'triggered_by' => $triggeredBy, 'counts' => $counts, 'error_log' => $log];
        }
        $skipCleanup = false;
        $forcedFailure = false;
        if (!empty($source['deactivate_missing'])) {
            $previousEmptyStreak = (int) $source['consecutive_empty_count'];
            if ($items === [] && $previousEmptyStreak === 0) {
                $this->sourceRepository->setConsecutiveEmptyCount($idSource, 1);
                $skipCleanup = true;
                $forcedFailure = true;
                $this->log($log, 'Feed returned 0 items; skipping deactivation as a likely transient scrape failure. Missing products will be deactivated if this repeats on the next run.');
            } elseif ($items === [] && $previousEmptyStreak > 0) {
                $this->sourceRepository->setConsecutiveEmptyCount($idSource, $previousEmptyStreak + 1);
                $this->log($log, 'Feed returned 0 items for the ' . ($previousEmptyStreak + 1) . ' consecutive run in a row; proceeding with deactivation since this no longer looks transient.');
            } elseif ($items !== [] && $previousEmptyStreak > 0) {
                $this->sourceRepository->setConsecutiveEmptyCount($idSource, 0);
            }
        }
        foreach ($items as $index => $item) {
            $externalId = null;
            try {
                if (!is_array($item) || !is_scalar($item[$source['identifier_field']] ?? null) || (string) $item[$source['identifier_field']] === '') {
                    throw new \RuntimeException('missing identifier_field value');
                }
                $externalId = (string) $item[$source['identifier_field']];
                if (!$this->filter->shouldImport($item, $source['filter_expression'] ?? null)) {
                    ++$counts['skipped'];
                    continue;
                }
                $existing = $this->productLinks->findProductId($idSource, $externalId);
                $mapped = $this->mapper->map($item, $source['field_mapping']);
                foreach ($mapped['errors'] as $field => $error) {
                    $this->log($log, "item $index field $field: $error");
                }
                $values = $mapped['values'];
                $categoryIds = isset($values['category_paths']) ? $this->categories->resolve($this->normalizer->normalize($values['category_paths']), $idSource, isset($source['root_category_id']) ? (int) $source['root_category_id'] : null, true, isset($source['default_id_category']) ? (int) $source['default_id_category'] : null) : [];
                $manufacturer = $this->manufacturers->resolve($values['manufacturer'] ?? null, $idSource, true, isset($source['default_id_manufacturer']) ? (int) $source['default_id_manufacturer'] : null);
                $idSupplier = isset($source['id_supplier']) && $source['id_supplier'] !== null ? (int) $source['id_supplier'] : null;
                $feedHash = self::feedHash($item, $source, $categoryIds, $manufacturer, $this->moduleVersion());
                if ($existing !== null && $this->productLinks->findFeedHash($idSource, $externalId) === $feedHash && $this->productLinks->productExists($existing)) {
                    $this->productLinks->markSeen($idSource, $externalId, $existing, $idRun);
                    ++$counts['unchanged'];
                    continue;
                }
                $idProduct = $this->products->import($idSource, $externalId, $values, $categoryIds, $manufacturer, $idSupplier, (int) $source['id_lang_default']);
                // Importers retain their existing signatures; refresh their link with this run ID.
                $this->productLinks->link($idSource, $externalId, $idProduct, $idRun);
                ++$counts[$existing === null ? 'created' : 'updated'];
                $configuration = $source['variant_mapping'] ?? null;
                $hasVariants = $configuration && trim($configuration['variants_expression'] ?? '') !== '';
                $variants = $hasVariants ? $this->variants->variants($item, $configuration['variants_expression']) : [];
                $basePrice = $hasVariants ? (isset($values['price']) ? (float) $values['price'] : $this->catalog->savedPrice($idProduct)) : 0.0;
                $variantFailed = false;
                foreach ($variants as $variantIndex => $variant) {
                    try {
                        $fields = $this->variants->mapFields($item, $variant, $configuration['fields']);
                        $attributes = $this->variants->mapAttributes($item, $variant, $configuration['attributes']);
                        foreach ($fields['errors'] as $field => $error) {
                            $this->log($log, "item $index variant $variantIndex field $field: $error");
                        }
                        if ($attributes['errors']) {
                            throw new \RuntimeException('Attribute errors: ' . json_encode($attributes['errors']));
                        }
                        $reference = $fields['values']['reference'] ?? null;
                        if (!is_scalar($reference) || trim((string) $reference) === '') {
                            throw new \RuntimeException('Missing variant reference.');
                        }
                        $ids = [];
                        foreach ($attributes['values'] as $group => $value) {
                            if ($value === null || trim($value) === '') {
                                throw new \RuntimeException('Empty attribute: ' . $group);
                            }
                            $ids[] = $this->attributes->resolve((string) $group, $value, true)['id_attribute'];
                        }
                        $idCombination = $this->combinations->import($idSource, $externalId, (string) $reference, $idProduct, $basePrice, $fields['values'], $ids);
                        $this->combinationLinks->link($idSource, $externalId . ':' . $reference, $idCombination, $idProduct, $idRun);
                    } catch (\Throwable $error) {
                        $variantFailed = true;
                        ++$counts['failed'];
                        $this->log($log, "item $index variant $variantIndex: " . $error->getMessage());
                    }
                }
                // Only a fully imported item may be skipped next time.
                $this->productLinks->setFeedHash($idSource, $externalId, $variantFailed ? null : $feedHash);
            } catch (\Throwable $error) {
                ++$counts['failed'];
                $this->log($log, "item $index: " . $error->getMessage());
                if (isset($externalId)) {
                    try {
                        $this->productLinks->setFeedHash($idSource, $externalId, null);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        if (!$skipCleanup && !empty($source['deactivate_missing'])) {
            try {
                foreach ($this->productLinks->findStaleForSource($idSource, $idRun) as $row) {
                    try {
                        // The import owns `active` only while it still holds the value the import last wrote.
                        $imported = ImportedValues::decode($row['imported_values'] ?? null);
                        $inactive = ImportedValues::fingerprint(['active' => '0'])['active'];
                        $importedActive = isset($imported['active']) ? $imported['active'] !== $inactive : null;
                        if ($this->catalog->deactivateProduct((int) $row['id_product'], $importedActive) && $importedActive !== false) {
                            $imported['active'] = $inactive;
                            $this->productLinks->saveImportedValues($idSource, (string) $row['external_id'], $imported);
                        }
                        // A returning identical item must be imported again, or it would stay inactive.
                        $this->productLinks->setFeedHash($idSource, (string) $row['external_id'], null);
                    } catch (\Throwable $error) {
                        ++$counts['failed'];
                        $this->log($log, 'Deactivate product: ' . $error->getMessage());
                    }
                }
                foreach ($this->combinationLinks->findStaleForSource($idSource, $idRun) as $row) {
                    try {
                        $this->catalog->zeroCombination((int) $row['id_product'], (int) $row['id_product_attribute']);
                    } catch (\Throwable $error) {
                        ++$counts['failed'];
                        $this->log($log, 'Deactivate combination: ' . $error->getMessage());
                    }
                }
            } catch (\Throwable $error) {
                ++$counts['failed'];
                $this->log($log, 'Stale lookup: ' . $error->getMessage());
            }
        }
        $status = $forcedFailure ? 'failed' : ($counts['failed'] > 0 ? 'completed_with_errors' : 'completed');
        $this->runs->finish($idRun, $status, $counts, $log);
        return ['id_source' => $idSource, 'id_run' => $idRun, 'status' => $status, 'triggered_by' => $triggeredBy, 'counts' => $counts, 'error_log' => $log];
    }

    public function runAll(array $sources, string $triggeredBy): array
    {
        $results = [];
        foreach ($sources as $source) {
            if (empty($source['active'])) {
                continue;
            }
            try {
                $results[] = $this->runOne($source, $triggeredBy);
            } catch (\Throwable $error) {
                $results[] = ['id_source' => $source['id_source'], 'status' => 'failed', 'triggered_by' => $triggeredBy, 'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0], 'error_log' => ImportRunRepository::capLog($error->getMessage())];
            }
        }
        return $results;
    }

    /**
     * Fingerprint of everything that decides what an import run writes for one item. Quantity is part of the raw
     * item, so a stock change yields a new hash and stock syncs only then.
     */
    public static function feedHash(array $item, array $source, array $categoryIds, mixed $manufacturer, string $moduleVersion): string
    {
        $payload = [
            'item' => $item,
            'field_mapping' => $source['field_mapping'] ?? null,
            'variant_mapping' => $source['variant_mapping'] ?? null,
            'id_lang_default' => isset($source['id_lang_default']) ? (int) $source['id_lang_default'] : null,
            'id_supplier' => isset($source['id_supplier']) ? (int) $source['id_supplier'] : null,
            'categories' => $categoryIds,
            'manufacturer' => $manufacturer,
            'version' => $moduleVersion,
        ];

        return md5((string) json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** Sorts object keys recursively so key order in the feed does not change the hash. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'canonical'], $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function moduleVersion(): string
    {
        if ($this->moduleVersion === null) {
            $module = \Module::getInstanceByName('productimport');
            $this->moduleVersion = $module ? (string) $module->version : '';
        }

        return $this->moduleVersion;
    }

    private function log(string &$log, string $message): void
    {
        $log = ImportRunRepository::capLog($log . ($log === '' ? '' : "\n") . $message);
    }
}
