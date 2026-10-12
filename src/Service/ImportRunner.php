<?php

namespace ProductImport\Service;

use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;
use ProductImport\Repository\SourceRepository;

class ImportRunner
{
    public function __construct(
        /** @var callable(array): array */
        private $fetcher,
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
        private FeatureMapper $features,
        private CarrierResolver $carriers,
        private ?string $moduleVersion = null
    ) {
    }

    /** Source mapping columns must be decoded by the caller, as for PreviewBuilder. */
    public function runOne(array $source, string $triggeredBy): array
    {
        $idSource = (int) $source['id_source'];
        $idRun = $this->runs->start($idSource, $triggeredBy);
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0];
        $log = '';
        try {
            $items = ($this->fetcher)($source);
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
        $tombstones = array_flip($this->productLinks->findTombstonedExternalIds($idSource));
        $tombstoneSkipped = 0;
        /** @var array<int, array{external_id: string, index: int|string, entries: list<string>}> */
        $pendingRelated = [];
        /** @var array<string, true> external ids of every item in this feed that passed the filter, whether it imported or failed */
        $present = [];
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
                $present[$externalId] = true;
                if (isset($tombstones[$externalId])) {
                    ++$counts['skipped'];
                    ++$tombstoneSkipped;
                    continue;
                }
                $existing = $this->productLinks->findProductId($idSource, $externalId);
                if ($existing !== null && !$this->productLinks->productExists($existing)) {
                    // Deleted before the delete hook existed or while it was missing: treat it as a deleted product.
                    $this->productLinks->tombstoneProduct($existing);
                    ++$counts['skipped'];
                    ++$tombstoneSkipped;
                    continue;
                }
                $mapped = $this->mapper->map($item, $source['field_mapping']);
                foreach ($mapped['errors'] as $field => $error) {
                    $this->log($log, "item $index field $field: $error");
                }
                $values = $mapped['values'];
                if (($warning = Gtin::warning($values['ean13'] ?? null)) !== null) {
                    unset($values['ean13']);
                    $this->log($log, "item $index field ean13: $warning");
                } elseif (is_scalar($values['ean13'] ?? null)) {
                    $values['ean13'] = trim((string) $values['ean13']);
                }
                try {
                    $categoryIds = isset($values['category_paths']) ? $this->categories->resolve($this->normalizer->normalize($values['category_paths']), $idSource, isset($source['root_category_id']) ? (int) $source['root_category_id'] : null, true, isset($source['default_id_category']) ? (int) $source['default_id_category'] : null) : [];
                } finally {
                    foreach ($this->categories->drainNotices() as $notice) {
                        $this->log($log, "item $index: $notice");
                    }
                }
                try {
                    $manufacturer = $this->manufacturers->resolve($values['manufacturer'] ?? null, $idSource, true, isset($source['default_id_manufacturer']) ? (int) $source['default_id_manufacturer'] : null);
                } finally {
                    foreach ($this->manufacturers->drainNotices() as $notice) {
                        $this->log($log, "item $index: $notice");
                    }
                }
                $idLang = (int) $source['id_lang_default'];
                // Features are resolved before the hash, like categories and brands: a changed mapping must re-import unchanged items.
                $rawFeatures = $values['features'] ?? null;
                unset($values['features']);
                // Logged only when the item is imported: an unchanged item would repeat them every run.
                $featureLog = [];
                // A variant `features` field adds each variant's values to the product's features.
                $configuration = $source['variant_mapping'] ?? null;
                $variantFeatures = is_array($configuration) ? trim((string) ($configuration['fields']['features'] ?? '')) : '';
                $variants = null;
                $variantFeatureValues = [];
                if ($variantFeatures !== '' && trim($configuration['variants_expression'] ?? '') !== '') {
                    $variants = $this->variants->variants($item, $configuration['variants_expression']);
                    foreach ($variants as $variantIndex => $variant) {
                        $mapped = $this->variants->mapFields($item, $variant, ['features' => $variantFeatures]);
                        if (isset($mapped['errors']['features'])) {
                            $featureLog[] = "variant $variantIndex: " . $mapped['errors']['features'];
                        } elseif ($mapped['values']['features'] !== null) {
                            $variantFeatureValues[$variantIndex] = $mapped['values']['features'];
                        }
                    }
                }
                if ($rawFeatures !== null || $variantFeatureValues !== []) {
                    try {
                        $resolved = $this->features->resolve($rawFeatures, $item, $idSource, (string) ($source['unmapped_features'] ?? FeatureMapping::UNMAPPED_IGNORE), $idLang, true, $variantFeatureValues);
                        $featureLog = array_merge($featureLog, $resolved['warnings']);
                        $values['features'] = $resolved['rows'];
                    } catch (\InvalidArgumentException $error) {
                        $featureLog[] = $error->getMessage();
                    }
                }
                $idSupplier = isset($source['id_supplier']) && $source['id_supplier'] !== null ? (int) $source['id_supplier'] : null;
                $feedHash = self::feedHash($item, $source, $categoryIds, $manufacturer, $values['features'] ?? null, $this->moduleVersion());
                if ($existing !== null && $this->productLinks->findFeedHash($idSource, $externalId) === $feedHash) {
                    $this->productLinks->markSeen($idSource, $externalId, $existing, $idRun);
                    ++$counts['unchanged'];
                    continue;
                }
                foreach ($featureLog as $warning) {
                    $this->log($log, "item $index field features: $warning");
                }
                // Only changed items reach this lookup; an unchanged item keeps what it was last written with.
                [$values, $warning] = ReferenceCheck::apply($values, $existing, fn (string $reference, ?int $except): ?int => $this->catalog->productIdByReference($reference, $except));
                if ($warning !== null) {
                    $this->log($log, "item $index field reference: $warning");
                }
                [$values, $related] = $this->nativeValues($values, $index, $idLang, $log);
                $idProduct = $this->products->import($idSource, $externalId, $values, $categoryIds, $manufacturer, $idSupplier, $idLang);
                // Importers retain their existing signatures; refresh their link with this run ID.
                $this->productLinks->link($idSource, $externalId, $idProduct, $idRun);
                if ($related !== null) {
                    $pendingRelated[$idProduct] = ['external_id' => $externalId, 'index' => $index, 'entries' => $related];
                }
                ++$counts[$existing === null ? 'created' : 'updated'];
                $hasVariants = $configuration && trim($configuration['variants_expression'] ?? '') !== '';
                $variants ??= $hasVariants ? $this->variants->variants($item, $configuration['variants_expression']) : [];
                $variantFieldMapping = array_diff_key($hasVariants ? $configuration['fields'] : [], ['features' => 1]);
                $basePrice = $hasVariants ? $this->catalog->variantBasePrice($idProduct) : 0.0;
                $variantFailed = false;
                foreach ($variants as $variantIndex => $variant) {
                    try {
                        $fields = $this->variants->mapFields($item, $variant, $variantFieldMapping);
                        $attributes = $this->variants->mapAttributes($item, $variant, $configuration['attributes']);
                        $limited = NativeFields::limitTexts($fields['values'], NativeFields::COMBINATION_TEXT_LIMITS);
                        $fields['values'] = $limited['values'];
                        foreach ($limited['warnings'] as $warning) {
                            $this->log($log, "item $index variant $variantIndex field $warning");
                        }
                        foreach ($fields['errors'] as $field => $error) {
                            $this->log($log, "item $index variant $variantIndex field $field: $error");
                        }
                        if (($warning = Gtin::warning($fields['values']['ean13'] ?? null)) !== null) {
                            unset($fields['values']['ean13']);
                            $this->log($log, "item $index variant $variantIndex field ean13: $warning");
                        } elseif (is_scalar($fields['values']['ean13'] ?? null)) {
                            $fields['values']['ean13'] = trim((string) $fields['values']['ean13']);
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
                        $idCombination = $this->combinations->import($idSource, $externalId, (string) $reference, $idProduct, $basePrice, $fields['values'], $ids, $idSupplier, $idLang);
                        $this->combinationLinks->link($idSource, $externalId . ':' . $reference, $idCombination, $idProduct, $idRun);
                    } catch (\Throwable $error) {
                        $variantFailed = true;
                        ++$counts['failed'];
                        $this->log($log, "item $index variant $variantIndex: " . $error->getMessage());
                    }
                }
                // Only a fully imported item may be skipped next time.
                $this->productLinks->setFeedHash($idSource, $externalId, $variantFailed ? null : $feedHash);
            } catch (ProductDeletedException) {
                if ($externalId !== null) {
                    $present[$externalId] = true;
                }
                ++$counts['skipped'];
                ++$tombstoneSkipped;
            } catch (\Throwable $error) {
                if ($externalId !== null) {
                    $present[$externalId] = true;
                }
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
        if ($pendingRelated !== []) {
            $this->importRelated($idSource, $pendingRelated, $counts, $log);
        }
        if ($tombstoneSkipped > 0) {
            $this->log($log, "$tombstoneSkipped item(s) skipped: their product was deleted in the store (see Deleted products on the source page to restore).");
        }
        if (!$skipCleanup && !empty($source['deactivate_missing'])) {
            try {
                $days = MissingLifecycle::normalizeDays($source['delete_missing_days'] ?? null);
                // A run with any failure (including an item without an identifier) cannot tell missing from broken: never delete in it.
                $deletionBlocked = $counts['failed'] > 0;
                if ($deletionBlocked && $days !== null) {
                    $this->log($log, 'Auto-delete skipped: this run had failures, so a missing product could be a failing one.');
                }
                foreach ($this->productLinks->findStaleForSource($idSource, $idRun) as $row) {
                    try {
                        $externalId = (string) $row['external_id'];
                        $idProduct = (int) $row['id_product'];
                        if (!$this->productLinks->productExists($idProduct)) {
                            // Deleted without the delete hook: a tombstone, not a failure.
                            $this->productLinks->tombstoneProduct($idProduct);
                            continue;
                        }
                        // An item present in the feed that failed to import is not missing: it is still hidden below, but its clock never starts and it is never deleted.
                        if (!isset($present[$externalId])) {
                            $now = time();
                            $dateMissing = $row['date_missing'] ?? null;
                            $started = MissingLifecycle::firstMissing($dateMissing, date('Y-m-d H:i:s', $now));
                            if ($started !== $dateMissing) {
                                $this->productLinks->markMissing($idSource, $externalId, $started);
                                $dateMissing = $started;
                            }
                            if (!$deletionBlocked && MissingLifecycle::isExpired($dateMissing, $days, $now)
                                && MissingLifecycle::shouldDelete($dateMissing, $days, $now, !$this->catalog->isProductActive($idProduct), $this->productLinks->hasLinkFromOtherSource($idProduct, $idSource, $externalId))) {
                                // Links go first: a failure after Product::delete() would otherwise leave a link to a deleted product, which the next run would tombstone.
                                $this->productLinks->unlink($idSource, $externalId);
                                $this->productLinks->forgetProductDetails($idProduct);
                                try {
                                    $this->catalog->deleteProduct($idProduct);
                                } catch (\Throwable $error) {
                                    if ($this->productLinks->productExists($idProduct)) {
                                        $this->productLinks->link($idSource, $externalId, $idProduct);
                                    }
                                    throw $error;
                                }
                                ++$counts['deleted'];
                                $this->log($log, "Deleted product $idProduct (external id $externalId): missing since $dateMissing.");
                                continue;
                            }
                        }
                        // The import owns `active` only while it still holds the value the import last wrote.
                        $imported = ImportedValues::decode($row['imported_values'] ?? null);
                        $inactive = ImportedValues::fingerprint(['active' => '0'])['active'];
                        $importedActive = isset($imported['active']) ? $imported['active'] !== $inactive : null;
                        if ($this->catalog->deactivateProduct($idProduct, $importedActive) && $importedActive !== false) {
                            $imported['active'] = $inactive;
                            $this->productLinks->saveImportedValues($idSource, $externalId, $imported);
                        }
                        // A returning identical item must be imported again, or it would stay inactive.
                        $this->productLinks->setFeedHash($idSource, $externalId, null);
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
                $results[] = ['id_source' => $source['id_source'], 'status' => 'failed', 'triggered_by' => $triggeredBy, 'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0], 'error_log' => ImportRunRepository::capLog($error->getMessage())];
            }
        }
        return $results;
    }

    /**
     * Normalises the native-field values of one item and resolves carriers. Features are already resolved (see runOne). Related
     * products come back separately: they are resolved in a second pass, once every product of the run exists.
     *
     * @return array{0: array<string, mixed>, 1: list<string>|null}
     */
    private function nativeValues(array $values, int|string $index, int $idLang, string &$log): array
    {
        $limited = NativeFields::limitTexts($values, NativeFields::PRODUCT_TEXT_LIMITS);
        $values = $limited['values'];
        foreach ($limited['warnings'] as $warning) {
            $this->log($log, "item $index field $warning");
        }
        try {
            $deliveryTime = NativeFields::deliveryTime($values['delivery_time'] ?? null);
        } catch (\InvalidArgumentException $error) {
            $deliveryTime = null;
            $this->log($log, "item $index field delivery_time: " . $error->getMessage());
        }
        unset($values['delivery_time']);
        if ($deliveryTime !== null) {
            $values['delivery_time'] = $deliveryTime;
        }
        if (isset($values['additional_shipping_cost']) && (!is_numeric($values['additional_shipping_cost']) || (float) $values['additional_shipping_cost'] < 0)) {
            unset($values['additional_shipping_cost']);
            $this->log($log, "item $index field additional_shipping_cost: expected a number of at least 0");
        }
        $carriers = $values['carriers'] ?? null;
        $relatedInput = $values['related_products'] ?? null;
        unset($values['carriers'], $values['related_products']);
        if ($carriers !== null) {
            $resolved = $this->carriers->resolve($carriers);
            foreach ($resolved['unknown'] as $entry) {
                $this->log($log, "item $index field carriers: \"$entry\" not found");
            }
            // An empty list means all carriers; a list with nothing but unknown carriers must not widen delivery.
            if ($resolved['ids'] !== [] || $resolved['unknown'] === []) {
                $values['carriers'] = $resolved['ids'];
            }
        }

        return [$values, $relatedInput === null ? null : NativeFields::stringList($relatedInput)];
    }

    /**
     * Second pass: every product of the run exists now. Entries match a feed identifier of this source first, then a
     * product reference. Errors are per product.
     *
     * @param array<int, array{external_id: string, index: int|string, entries: list<string>}> $pending
     */
    private function importRelated(int $idSource, array $pending, array &$counts, string &$log): void
    {
        $byFeedId = [];
        $byReference = [];
        $feedLookup = function (string $entry) use ($idSource, &$byFeedId): ?int {
            if (!array_key_exists($entry, $byFeedId)) {
                $id = $this->productLinks->findProductId($idSource, $entry);
                $byFeedId[$entry] = $id !== null && $this->productLinks->productExists($id) ? $id : null;
            }
            return $byFeedId[$entry];
        };
        $referenceLookup = function (string $entry) use (&$byReference): ?int {
            if (!array_key_exists($entry, $byReference)) {
                $byReference[$entry] = $this->catalog->productIdByReference($entry);
            }
            return $byReference[$entry];
        };
        foreach ($pending as $idProduct => $row) {
            try {
                $resolved = RelatedProductsResolver::resolve($row['entries'], $idProduct, $feedLookup, $referenceLookup);
                foreach ($resolved['missing'] as $entry) {
                    $this->log($log, "item {$row['index']} field related_products: \"$entry\" not found");
                }
                // An empty list clears the related products; a list with nothing found must not.
                if ($resolved['ids'] !== [] || $row['entries'] === []) {
                    $this->products->importRelated($idSource, $row['external_id'], $idProduct, $resolved['ids']);
                }
            } catch (\Throwable $error) {
                ++$counts['failed'];
                $this->log($log, "item {$row['index']} field related_products: " . $error->getMessage());
            }
        }
    }

    /**
     * Fingerprint of everything that decides what an import run writes for one item. Quantity is part of the raw
     * item, so a stock change yields a new hash and stock syncs only then.
     */
    public static function feedHash(array $item, array $source, array $categoryIds, mixed $manufacturer, ?array $features, string $moduleVersion): string
    {
        $payload = [
            'item' => $item,
            'field_mapping' => $source['field_mapping'] ?? null,
            'variant_mapping' => $source['variant_mapping'] ?? null,
            'id_lang_default' => isset($source['id_lang_default']) ? (int) $source['id_lang_default'] : null,
            'id_supplier' => isset($source['id_supplier']) ? (int) $source['id_supplier'] : null,
            'categories' => $categoryIds,
            'manufacturer' => $manufacturer,
            'features' => $features,
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
