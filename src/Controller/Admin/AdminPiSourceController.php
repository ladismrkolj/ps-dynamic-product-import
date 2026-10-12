<?php

use ProductImport\Repository\SourceRepository;

// Controller classes must be global for PrestaShop's tab dispatcher.
// PS9 removed the l()/translation fallback from AdminController; it
// only exists on ModuleAdminController now.
class AdminPiSourceController extends ModuleAdminController
{
    private $sources;
    private $submittedSource;
    private $submittedMapping;
    private $submittedVariant;
    private $submittedStaged = '';
    private $migrationReportHtml = '';

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'pi_source';
        $this->identifier = 'id_source';
        $this->lang = false;
        parent::__construct();
        $this->sources = new SourceRepository();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'productimport/views/css/admin.css?v=' . (int) @filemtime(dirname(__DIR__, 3) . '/views/css/admin.css'));
    }

    public function postProcess()
    {
        $actions = ['runImport' => 'ajaxProcessRunImport', 'importStatus' => 'ajaxProcessImportStatus', 'sampleValues' => 'ajaxProcessSampleValues', 'previewTable' => 'ajaxProcessPreviewTable', 'inspect' => 'ajaxProcessInspect', 'discoverCategories' => 'ajaxProcessDiscoverCategories', 'mappings' => 'ajaxProcessMappings', 'featureMappings' => 'ajaxProcessFeatureMappings', 'discoverFeatures' => 'ajaxProcessDiscoverFeatures', 'exportSource' => 'ajaxProcessExportSource', 'importSource' => 'ajaxProcessImportSource'];
        $action = Tools::getValue('action');
        if ($this->ajax && is_string($action) && isset($actions[$action])) {
            $this->{$actions[$action]}();
            return;
        }
        if (Tools::isSubmit('submitPiFeedDir')) {
            $this->saveFeedDir();

            return;
        }
        if (Tools::isSubmit('submitPiMigrate')) {
            $this->migrateTables();

            return;
        }
        if (Tools::isSubmit('submitPiRestore')) {
            $this->restoreDeleted();

            return;
        }
        $saving = Tools::isSubmit('submitAddpi_source');
        $deleting = Tools::isSubmit('deletepi_source');
        if (!$saving && !$deleting) {
            return;
        }
        $id = (int) Tools::getValue('id_source');
        $permission = $deleting ? 'delete' : ($id > 0 ? 'edit' : 'add');
        if (!$this->access($permission) || !$this->checkToken()) {
            $this->errors[] = $this->trans('You do not have permission or your security token is invalid.');

            return;
        }
        try {
            if ($id > 0 && !$this->sources->find($id)) {
                throw new InvalidArgumentException('Source not found.');
            }
            if ($deleting) {
                if ($id <= 0 || !$this->sources->delete($id)) {
                    throw new RuntimeException(\ProductImport\Repository\DbError::message('Unable to delete source'));
                }
            } else {
                $data = $this->readSubmission();
                $staged = $id > 0 ? $this->readStaged() : [];
                if ($id > 0) {
                    $existing = $this->sources->find($id);
                    $db = Db::getInstance();
                    $db->execute('START TRANSACTION');
                    try {
                        if (!$this->sources->update($id, $data + $this->stagedSourceColumns($staged))) {
                            throw new RuntimeException(\ProductImport\Repository\DbError::message('Unable to update source'));
                        }
                        $this->applyStagedRows($id, $data + $existing, $staged);
                        $db->execute('COMMIT');
                    } catch (Throwable $error) {
                        $db->execute('ROLLBACK');
                        throw $error;
                    }
                } else {
                    $id = $this->sources->create($data);
                }
            }
            $url = $this->context->link->getAdminLink('AdminPiSource');
            Tools::redirectAdmin($deleting ? $url . '&conf=1' : $url . '&updatepi_source&id_source=' . $id . '&conf=' . ($saving && $permission === 'add' ? 3 : 4));
        } catch (Throwable $error) {
            $this->errors[] = Tools::safeOutput($error->getMessage());
            if ($saving) {
                $this->display = $id > 0 ? 'edit' : 'add';
            }
        }
    }

    public function ajaxProcessRunImport()
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkToken() || !$this->access('edit')) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $raw = $_POST['id_source'] ?? null;
            if (!is_string($raw) || ($raw !== 'all' && (!ctype_digit($raw) || (int) $raw <= 0 || (string) (int) $raw !== $raw))) {
                throw new InvalidArgumentException('id_source must be all or a positive integer.');
            }
            $selection = $raw === 'all' ? 'all' : (int) $raw;
            $sources = \ProductImport\Service\ImportLauncher::selectSources($this->sources->findAll(), $selection);
            if (($_POST['overwrite_manual'] ?? '') === '1') {
                // Forgetting what the import last wrote makes this run overwrite every mapped field again.
                $products = new \ProductImport\Repository\ExternalProductRepository();
                $combinations = new \ProductImport\Repository\ExternalCombinationRepository();
                foreach ($sources as $source) {
                    $products->resetImportedValues((int) $source['id_source']);
                    $combinations->resetImportedValues((int) $source['id_source']);
                }
            }
            try {
                $result = (new \ProductImport\Service\BackgroundImport())->start($selection, 'admin-background');
                $result['mode'] = 'background';
            } catch (\ProductImport\Service\BackgroundImportUnsupportedException $error) {
                set_time_limit(0);
                $result = (new \ProductImport\Service\ImportLauncher())->run($sources, 'admin-manual');
                $names = array_column($sources, 'name', 'id_source');
                foreach ($result['runs'] as &$run) {
                    $run['name'] = $names[$run['id_source']] ?? '';
                }
                unset($run);
                $result['mode'] = 'inline';
            }
            $result['runs_url'] = $this->context->link->getAdminLink('AdminPiRunLog') . ($selection === 'all' ? '' : '&id_source=' . $selection);
        } catch (\ProductImport\Service\ImportLockBusyException $error) {
            http_response_code(409);
            $result = ['error' => $error->getMessage()];
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            $result = ['error' => $error->getMessage()];
        } catch (Throwable $error) {
            http_response_code(500);
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    public function ajaxProcessImportStatus()
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkToken() || !$this->access('edit')) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $raw = $_POST['id_source'] ?? null;
            $baseline = $_POST['baseline_run_id'] ?? null;
            if (!is_string($raw) || ($raw !== 'all' && (!ctype_digit($raw) || (int) $raw <= 0 || (string) (int) $raw !== $raw))) {
                throw new InvalidArgumentException('id_source must be all or a positive integer.');
            }
            if (!is_string($baseline) || !ctype_digit($baseline) || (string) (int) $baseline !== $baseline) {
                throw new InvalidArgumentException('baseline_run_id must be a non-negative integer.');
            }
            $selection = $raw === 'all' ? 'all' : (int) $raw;
            $sources = \ProductImport\Service\ImportLauncher::selectSources($this->sources->findAll(), $selection);
            $rows = (new \ProductImport\Repository\ImportRunRepository())->findAfter(array_column($sources, 'id_source'), (int) $baseline);
            $runs = [];
            foreach ($rows as $row) {
                $runs[] = ['id_run' => (int) $row['id_run'], 'id_source' => (int) $row['id_source'],
                    'name' => (string) ($row['source_name'] ?? ''), 'status' => $row['status'],
                    'triggered_by' => $row['triggered_by'],
                    'counts' => ['created' => (int) $row['created_count'], 'updated' => (int) $row['updated_count'], 'unchanged' => (int) $row['unchanged_count'],
                        'skipped' => (int) $row['skipped_count'], 'failed' => (int) $row['failed_count'], 'deleted' => (int) $row['deleted_count']],
                    'error_log' => (string) ($row['error_log'] ?? '')];
            }
            $result = ['running' => \ProductImport\Service\ImportLauncher::isRunning(), 'runs' => $runs];
        } catch (\ProductImport\Service\ImportLockBusyException $error) {
            http_response_code(409);
            $result = ['error' => $error->getMessage()];
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            $result = ['error' => $error->getMessage()];
        } catch (Throwable $error) {
            http_response_code(500);
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    /** @return array{0: ?int, 1: ?array} id_source (null for a new source) and the saved source row */
    private function editorContext(): array
    {
        $rawId = $_POST['id_source'] ?? '';
        if (!is_string($rawId) || ($rawId !== '' && (!ctype_digit($rawId) || (int) $rawId <= 0))) {
            throw new InvalidArgumentException('id_source must be a positive integer when provided.');
        }
        $idSource = $rawId === '' ? null : (int) $rawId;
        if (!$this->checkToken() || !$this->access($idSource === null ? 'add' : 'edit')) {
            throw new RuntimeException('Permission denied or invalid security token.');
        }
        $source = $idSource === null ? null : $this->sources->find($idSource);
        if ($idSource !== null && !$source) {
            throw new InvalidArgumentException('Source not found.');
        }

        return [$idSource, $source];
    }

    /** The submitted feed location, which may differ from the saved one. */
    private function feedInput(): array
    {
        $input = [];
        foreach (['location', 'source_format', 'xml_item_path'] as $key) {
            $value = $_POST[$key] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException($key . ' must be a string.');
            }
            $input[$key] = $value;
        }

        return $input;
    }

    /** Editor-only cached fetch; import runs never use it. refresh=1 bypasses the cache. */
    private function cachedFeed(array $location): array
    {
        return (new \ProductImport\Service\FeedCache())->get($location, ($_POST['refresh'] ?? '') === '1');
    }

    private function cacheInfo(array $feed): array
    {
        return ['cached' => $feed['cached'], 'age' => $feed['age'], 'fetched_at' => $feed['fetched_at']];
    }

    public function ajaxProcessPreviewTable()
    {
        try {
            [$idSource, $saved] = $this->editorContext();
            $feed = $this->cachedFeed($this->feedInput());
            if ($feed['items'] === []) {
                throw new InvalidArgumentException('Source feed must contain products.');
            }
            $mapping = [];
            $targets = $_POST['field_mapping_target'] ?? [];
            $expressions = $_POST['field_mapping_expression'] ?? [];
            if (!is_array($targets) || !is_array($expressions) || array_keys($targets) !== array_keys($expressions)) {
                throw new InvalidArgumentException('Invalid mapping rows.');
            }
            foreach ($targets as $index => $target) {
                if (!is_string($target) || !is_string($expressions[$index])) {
                    throw new InvalidArgumentException('Mapping rows must contain strings.');
                }
                [$target, $expression] = [trim($target), trim($expressions[$index])];
                if ($target === '' && $expression === '') {
                    continue;
                }
                if ($target === '' || $expression === '' || array_key_exists($target, $mapping)) {
                    throw new InvalidArgumentException('Each mapping row needs a unique target and an expression.');
                }
                $mapping[$target] = $expression;
            }
            $variantMapping = null;
            $variantsExpression = $_POST['variants_expression'] ?? '';
            if (!is_string($variantsExpression)) {
                throw new InvalidArgumentException('Invalid variants expression.');
            }
            if (trim($variantsExpression) !== '') {
                $attributes = $this->rowsToMapping($this->readRows('variant_attribute'));
                $fields = $this->rowsToMapping($this->readRows('variant_field'));
                if ($attributes === [] || !isset($fields['reference'])) {
                    throw new InvalidArgumentException('Variants require at least one attribute and a reference field mapping.');
                }
                $definitions = [];
                foreach ($attributes as $name => $expression) {
                    $definitions[] = ['name' => (string) $name, 'expression' => $expression];
                }
                $variantMapping = ['variants_expression' => trim($variantsExpression), 'attributes' => $definitions, 'fields' => $fields];
            }
            $filter = $_POST['filter_expression'] ?? '';
            if (!is_string($filter)) {
                throw new InvalidArgumentException('Invalid filter expression.');
            }
            $evaluator = new \ProductImport\Service\ExpressionEvaluator();
            $normalizer = new \ProductImport\Service\CategoryPathNormalizer();
            $builder = new \ProductImport\Service\PreviewBuilder(
                new \ProductImport\Service\ProductFilter($evaluator),
                new \ProductImport\Service\ProductFieldMapper($evaluator),
                $normalizer,
                new \ProductImport\Service\CategoryResolver(new \ProductImport\Repository\CategoryMappingRepository(), $normalizer),
                new \ProductImport\Service\ManufacturerResolver(),
                new \ProductImport\Service\VariantFieldMapper($evaluator),
                new \ProductImport\Service\AttributeResolver(),
                new \ProductImport\Service\FeatureMapper(new \ProductImport\Repository\FeatureMappingRepository(), new \ProductImport\Service\FeatureResolver(), $evaluator)
            );
            $result = $builder->table([
                'id_source' => (int) $idSource,
                'filter_expression' => trim($filter) === '' ? null : $filter,
                'root_category_id' => $saved['root_category_id'] ?? null,
                'default_id_category' => $saved['default_id_category'] ?? null,
                'default_id_manufacturer' => $saved['default_id_manufacturer'] ?? null,
                'unmapped_features' => $saved['unmapped_features'] ?? \ProductImport\Service\FeatureMapping::UNMAPPED_IGNORE,
                'id_lang_default' => (int) ($saved['id_lang_default'] ?? Configuration::get('PS_LANG_DEFAULT')),
                'identifier_field' => $_POST['identifier_field'] ?? ($saved['identifier_field'] ?? ''),
                'field_mapping' => $mapping,
                'variant_mapping' => $variantMapping,
            ], $feed['items'], \ProductImport\Service\PreviewBuilder::TABLE_LIMIT, $this->referenceOwner((int) $idSource, $saved ?? []), (new \ProductImport\Service\CatalogOptions())->carriers());
            $result['item_count'] = count($feed['items']);
            $result['cache'] = $this->cacheInfo($feed);
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    /** Read-only lookup for the preview: the product, other than the item's linked one, holding a reference. */
    private function referenceOwner(int $idSource, array $saved): callable
    {
        $identifier = $_POST['identifier_field'] ?? ($saved['identifier_field'] ?? '');
        $links = new \ProductImport\Repository\ExternalProductRepository();
        $catalog = new \ProductImport\Service\ImportCatalog();

        return static function (string $reference, array $item) use ($identifier, $idSource, $links, $catalog): ?int {
            $externalId = is_string($identifier) && is_scalar($item[$identifier] ?? null) ? (string) $item[$identifier] : null;
            $linked = $idSource > 0 && $externalId !== null && $externalId !== '' ? $links->findProductId($idSource, $externalId) : null;

            return $catalog->productIdByReference($reference, $linked);
        };
    }

    public function ajaxProcessInspect()
    {
        try {
            [, $source] = $this->editorContext();
            $input = $this->feedInput();
            if (($input['source_format'] ?? null) === 'xml' && trim((string) ($input['xml_item_path'] ?? '')) === '') {
                $paths = (new \ProductImport\Service\FeedCache())->itemPaths($input, ($_POST['refresh'] ?? '') === '1');
                if ($paths['items'] === []) {
                    throw new InvalidArgumentException('No repeating XML elements found; enter the item path manually.');
                }
                $this->sendJson(['item_path_candidates' => $paths['items'], 'cache' => $this->cacheInfo($paths)]);

                return;
            }
            $feed = $this->cachedFeed($input);
            if ($feed['items'] === []) {
                throw new InvalidArgumentException('Source feed must contain products.');
            }
            $result = ['item_count' => count($feed['items']), 'schema' => (new \ProductImport\Service\FeedSchema())->scan($feed['items']), 'cache' => $this->cacheInfo($feed)];
            if ($source !== null) {
                $result['discovery'] = $this->discoverSaved($feed['items'], $source);
            }
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    /**
     * Records the categories and brands of a loaded feed using the saved expressions. Failures are reported per section so
     * they never block the editor. `new` counts the mapping rows this run created.
     *
     * @return array{categories: array, brands: array}
     */
    private function discoverSaved(array $items, array $source): array
    {
        $discovery = [];
        try {
            $mapping = json_decode((string) $source['field_mapping'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($mapping)) {
                throw new InvalidArgumentException('Saved field_mapping must be a JSON object.');
            }
            $mappingError = null;
        } catch (Throwable $error) {
            $mapping = [];
            $mappingError = $error->getMessage();
        }
        $idSource = (int) $source['id_source'];
        foreach (['categories' => 'category_paths', 'brands' => 'manufacturer'] as $section => $target) {
            try {
                if ($mappingError !== null) {
                    throw new InvalidArgumentException($mappingError);
                }
                $expression = $mapping[$target] ?? null;
                if ($expression !== null && !is_string($expression)) {
                    throw new InvalidArgumentException('Saved ' . $target . ' expression must be a string.');
                }
                if ($expression === null || trim($expression) === '') {
                    $discovery[$section] = ['skipped' => 'no saved ' . $target . ' expression'];
                    continue;
                }
                $repository = $section === 'brands' ? new \ProductImport\Repository\ManufacturerMappingRepository() : new \ProductImport\Repository\CategoryMappingRepository();
                $before = count($repository->findAllForSource($idSource));
                $found = $this->discoverValues($items, $idSource, $expression, $section === 'brands');
                $found['new'] = max(0, count($repository->findAllForSource($idSource)) - $before);
                $discovery[$section] = $found;
            } catch (Throwable $error) {
                $discovery[$section] = ['error' => $error->getMessage()];
            }
        }

        return $discovery;
    }

    public function ajaxProcessDiscoverCategories()
    {
        try {
            $source = $this->requireSource('edit');
            $mapping = json_decode($source['field_mapping'], true, 512, JSON_THROW_ON_ERROR);
            $expression = $mapping['category_paths'] ?? null;
            if (!is_string($expression) || trim($expression) === '') {
                throw new InvalidArgumentException('Save a category_paths expression before discovery.');
            }
            $items = $this->cachedFeed($source)['items'];
            $result = $this->discoverValues($items, (int) $source['id_source'], $expression, false);
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    public function ajaxProcessSampleValues()
    {
        try {
            $this->editorContext();
            $expression = $_POST['expression'] ?? null;
            if (!is_string($expression) || trim($expression) === '') {
                throw new InvalidArgumentException('Map a source field first.');
            }
            $rawSize = $_POST['sample_size'] ?? null;
            $size = is_string($rawSize) && ctype_digit($rawSize) && (int) $rawSize > 0 ? min(500, (int) $rawSize) : 50;
            $variantsExpression = $_POST['variants_expression'] ?? null;
            if ($variantsExpression !== null && (!is_string($variantsExpression) || trim($variantsExpression) === '')) {
                throw new InvalidArgumentException('Choose a variants list first.');
            }
            $items = $this->cachedFeed($this->feedInput())['items'];
            $sampler = new \ProductImport\Service\ValueSampler();
            $result = $variantsExpression === null
                ? $sampler->sample(array_slice($items, 0, $size), $expression)
                : $sampler->sample($items, $expression, trim($variantsExpression), $size);
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    /** Full-feed scan shared by the discovery button and the automatic discovery after a feed load; writes only after deduplication. */
    private function discoverValues(array $items, int $idSource, string $expression, bool $brands): array
    {
        $evaluator = new \ProductImport\Service\ExpressionEvaluator();
        $normalizer = new \ProductImport\Service\CategoryPathNormalizer();
        $values = [];
        $failed = 0;
        $errors = [];
        foreach ($items as $index => $item) {
            try {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('Item is not an object.');
                }
                $value = $evaluator->evaluate($expression, ['fields' => $item]);
                if ($brands) {
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Manufacturer must be a string or null.');
                    }
                    $name = trim($value ?? '');
                    if (mb_strlen($name) > 191) {
                        throw new InvalidArgumentException('Manufacturer name exceeds 191 characters.');
                    }
                    if ($name !== '') {
                        $values['name:' . $name] = $name;
                    }
                } else {
                    foreach ($normalizer->normalize($value) as $path) {
                        $values[$normalizer->hash($path)] = $path;
                    }
                }
            } catch (Throwable $error) {
                ++$failed;
                if (count($errors) < 20) {
                    $errors[] = 'Item ' . $index . ': ' . mb_substr($error->getMessage(), 0, 500);
                }
            }
        }
        if ($brands) {
            $resolver = new \ProductImport\Service\ManufacturerResolver();
            foreach ($values as $name) {
                $resolver->discover($name, $idSource);
            }
        } else {
            (new \ProductImport\Service\CategoryResolver(new \ProductImport\Repository\CategoryMappingRepository(), $normalizer))->discover(array_values($values), $idSource);
        }
        return [$brands ? 'unique_brands' : 'unique_paths' => count($values), 'items_scanned' => count($items), 'failed_items' => $failed, 'errors' => $errors,
            'errors_omitted' => max(0, $failed - count($errors))];
    }

    private function mappingContext(string $permission): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new InvalidArgumentException('POST required.');
        }
        $source = $this->requireSource($permission);
        $type = Tools::getValue('type');
        if (!in_array($type, ['category', 'brand'], true)) {
            throw new InvalidArgumentException('Invalid mapping type.');
        }
        $category = $type === 'category';
        $options = new \ProductImport\Service\CatalogOptions();
        $options = $category ? $options->categories((int) $this->context->shop->id, (int) $this->context->language->id) : $options->manufacturers();
        $idColumn = $category ? 'id_category' : 'id_manufacturer';
        $names = array_column($options, 'label', $idColumn);
        return [$source, $category, $options, $names, $idColumn];
    }

    /** Name without the tree dashes and without the trailing " #id" of an option label. */
    private static function plainName(string $label): string
    {
        return trim((string) preg_replace('/\s+#\d+$/', '', (string) preg_replace('/^[—\s-]+/u', '', $label)));
    }

    /** @return array{status: string, badge: string, kind: string, detail: string} full text for search and hover, short badge and a muted detail */
    private function mappingStatus(?int $id, $default, array $names, bool $category): array
    {
        if ($id === null) {
            $detail = $default !== null ? '→ source default #' . $default : '→ found or created by name';
            return ['status' => 'not mapped ' . $detail, 'badge' => 'Not mapped', 'kind' => 'off', 'detail' => $detail];
        }
        $label = $names[$id] ?? null;
        $detail = $label === null ? 'missing or unavailable #' . $id : self::plainName($label) . ' #' . $id;
        return ['status' => 'existing ' . ($category ? 'category' : 'manufacturer') . ' ' . ($label ?? '#' . $id), 'badge' => $label === null ? 'Missing' : 'Mapped', 'kind' => $label === null ? 'warn' : 'ok', 'detail' => $detail];
    }

    public function ajaxProcessMappings()
    {
        try {
            [$source, $category, $options, $names, $idColumn] = $this->mappingContext('view');
            $repository = $category ? new \ProductImport\Repository\CategoryMappingRepository() : new \ProductImport\Repository\ManufacturerMappingRepository();
            $default = $source['default_' . $idColumn];
            $rows = [];
            $stored = $repository->findAllForSource((int) $source['id_source']);
            $stale = \ProductImport\Service\StaleMappingRows::flag(array_column($stored, 'date_seen'));
            foreach ($stored as $at => $row) {
                $id = $row[$idColumn] === null ? null : (int) $row[$idColumn];
                $rows[] = ['stale' => $stale['stale'][$at], 'mapped' => $id !== null, 'key' => $category ? $row['source_path_hash'] : $row['source_name'],
                    'label' => $category ? implode(' > ', json_decode($row['source_path'], true, 512, JSON_THROW_ON_ERROR)) : $row['source_name'],
                    'id' => $id, 'seen' => $row['date_seen'] ?: null] + $this->mappingStatus($id, $default, $names, $category);
            }
            $this->sendJson(['default' => $default === null ? null : (int) $default, 'rows' => $rows, 'stale_cutoff' => $stale['cutoff'], 'stale_hours' => \ProductImport\Service\StaleMappingRows::WINDOW_SECONDS / 3600,
                'options' => array_map(static function ($option) use ($idColumn) {
                    return ['id' => $option[$idColumn], 'label' => $option['label']];
                }, $options)]);
        } catch (Throwable $error) {
            $this->sendJson(['error' => $error->getMessage()]);
        }
    }

    /** The source of a feature-tab request; POST only. */
    private function featureSource(string $permission): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new InvalidArgumentException('POST required.');
        }

        return $this->requireSource($permission);
    }

    /** @return array{status: string, badge: string, kind: string, detail: string} */
    private function featureStatus(array $row, string $setting, array $names): array
    {
        $rule = \ProductImport\Service\FeatureMapping::effective($row);
        switch ($rule['action']) {
            case \ProductImport\Service\FeatureMapping::IGNORE:
                return ['status' => 'ignored', 'badge' => 'Ignored', 'kind' => 'warn', 'detail' => ''];
            case \ProductImport\Service\FeatureMapping::MAP:
                $label = $names[$rule['id_feature']] ?? null;
                return ['status' => 'existing feature #' . $rule['id_feature'] . ' (' . ($label ?? 'missing or unavailable') . ')', 'badge' => $label === null ? 'Missing' : 'Mapped',
                    'kind' => $label === null ? 'warn' : 'ok', 'detail' => $label === null ? 'missing or unavailable #' . $rule['id_feature'] : self::plainName($label) . ' #' . $rule['id_feature']];
            case \ProductImport\Service\FeatureMapping::CREATE:
                return ['status' => 'creates the feature "' . $rule['create_name'] . '"', 'badge' => 'Create', 'kind' => 'ok', 'detail' => $rule['create_name'] . ' (on the next import)'];
        }
        $created = $setting === \ProductImport\Service\FeatureMapping::UNMAPPED_CREATE;
        return ['status' => 'not mapped' . ($created ? ' becomes a feature named like the feed name' : ' not imported'), 'badge' => 'Not mapped', 'kind' => 'off', 'detail' => $created ? '→ created by name (setting)' : '→ ignored (setting)'];
    }

    public function ajaxProcessFeatureMappings()
    {
        try {
            $source = $this->featureSource('view');
            $options = (new \ProductImport\Service\CatalogOptions())->features((int) $source['id_lang_default']);
            $names = array_column($options, 'label', 'id_feature');
            $setting = (string) $source['unmapped_features'];
            $rows = [];
            $stored = (new \ProductImport\Repository\FeatureMappingRepository())->findAllForSource((int) $source['id_source']);
            $stale = \ProductImport\Service\StaleMappingRows::flag(array_column($stored, 'date_seen'));
            foreach ($stored as $at => $row) {
                $samples = json_decode((string) $row['sample_values'], true);
                $rows[] = ['stale' => $stale['stale'][$at], 'mapped' => $row['action'] !== 'none', 'key' => $row['source_name'], 'action' => $row['action'], 'id_feature' => $row['id_feature'] === null ? null : (int) $row['id_feature'],
                    'create_name' => $row['create_name'], 'value_expression' => $row['value_expression'], 'count' => (int) $row['product_count'],
                    'samples' => is_array($samples) ? $samples : [], 'variant_count' => (int) $row['variant_count'], 'variant_samples' => is_array($vs = json_decode((string) $row['variant_samples'], true)) ? $vs : [], 'seen' => $row['date_seen'] ?: null] + $this->featureStatus($row, $setting, $names);
            }
            $this->sendJson(['setting' => $setting, 'rows' => $rows, 'stale_cutoff' => $stale['cutoff'], 'stale_hours' => \ProductImport\Service\StaleMappingRows::WINDOW_SECONDS / 3600,
                'options' => array_map(static function ($option) {
                    return ['id' => $option['id_feature'], 'label' => $option['label']];
                }, $options)]);
        } catch (Throwable $error) {
            $this->sendJson(['error' => $error->getMessage()]);
        }
    }

    /** Records the feed's feature names with product counts and samples, from the saved `features` expression over the whole feed. */
    public function ajaxProcessDiscoverFeatures()
    {
        try {
            $source = $this->featureSource('edit');
            $mapping = json_decode($source['field_mapping'], true, 512, JSON_THROW_ON_ERROR);
            $expression = $mapping['features'] ?? null;
            $expression = is_string($expression) && trim($expression) !== '' ? $expression : null;
            $variant = json_decode((string) ($source['variant_mapping'] ?? ''), true);
            $variantsExpression = is_array($variant) && is_string($variant['variants_expression'] ?? null) && trim($variant['variants_expression']) !== '' ? $variant['variants_expression'] : null;
            $variantFeatures = is_array($variant) && is_string($variant['fields']['features'] ?? null) && trim($variant['fields']['features']) !== '' ? $variant['fields']['features'] : null;
            if ($expression === null && ($variantsExpression === null || $variantFeatures === null)) {
                throw new InvalidArgumentException('Save a features expression (product or variant) before discovery.');
            }
            $mapper = new \ProductImport\Service\FeatureMapper(new \ProductImport\Repository\FeatureMappingRepository(), new \ProductImport\Service\FeatureResolver(), new \ProductImport\Service\ExpressionEvaluator());
            $result = $mapper->discover($this->cachedFeed($source)['items'], (int) $source['id_source'], $expression, $variantsExpression, $variantFeatures);
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    /** The tab changes staged in the browser, posted as JSON with the main form. Empty when there are none. */
    private function readStaged(): array
    {
        $raw = Tools::getValue('pi_staged', '');
        $this->submittedStaged = is_string($raw) ? $raw : '';
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('The staged mapping changes are not valid.');
        }

        return $data;
    }

    private function stagedDestination($value, array $options, string $idColumn, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value <= 0 || !in_array((int) $value, array_column($options, $idColumn), true)) {
            throw new InvalidArgumentException($label . ' is not available in this shop.');
        }

        return (int) $value;
    }

    /** Source columns changed by the staged defaults and feature setting; validated like the old immediate saves. */
    private function stagedSourceColumns(array $staged): array
    {
        $columns = [];
        $catalog = new \ProductImport\Service\CatalogOptions();
        if (array_key_exists('default', $staged['category'] ?? [])) {
            $columns['default_id_category'] = $this->stagedDestination($staged['category']['default'], $catalog->categories((int) $this->context->shop->id, (int) $this->context->language->id), 'id_category', 'The default category');
        }
        if (array_key_exists('default', $staged['brand'] ?? [])) {
            $columns['default_id_manufacturer'] = $this->stagedDestination($staged['brand']['default'], $catalog->manufacturers(), 'id_manufacturer', 'The default brand');
        }
        if (array_key_exists('setting', $staged['feature'] ?? [])) {
            $setting = $staged['feature']['setting'];
            if (!is_string($setting) || !in_array($setting, \ProductImport\Service\FeatureMapping::UNMAPPED, true)) {
                throw new InvalidArgumentException('Invalid setting for feature names without a mapping.');
            }
            $columns['unmapped_features'] = $setting;
        }

        return $columns;
    }

    /** Writes the staged row changes of the three mapping tabs. Runs inside the save transaction. */
    private function applyStagedRows(int $idSource, array $source, array $staged): void
    {
        $catalog = new \ProductImport\Service\CatalogOptions();
        foreach (['category', 'brand'] as $type) {
            $part = $staged[$type] ?? [];
            if (!is_array($part) || ($part['rows'] ?? []) === [] && ($part['add'] ?? []) === [] && ($part['del'] ?? []) === []) {
                continue;
            }
            $category = $type === 'category';
            $options = $category ? $catalog->categories((int) $this->context->shop->id, (int) $this->context->language->id) : $catalog->manufacturers();
            $idColumn = $category ? 'id_category' : 'id_manufacturer';
            $repository = $category ? new \ProductImport\Repository\CategoryMappingRepository() : new \ProductImport\Repository\ManufacturerMappingRepository();
            $unknown = $category ? 'Unknown source path.' : 'Unknown source brand.';
            foreach ((array) ($part['add'] ?? []) as $add) {
                $id = $this->stagedDestination($add['value'] ?? '', $options, $idColumn, $category ? 'The category' : 'The brand');
                $label = $add['label'] ?? null;
                if (!is_string($label) || trim($label) === '') {
                    throw new InvalidArgumentException($category ? 'Enter the feed category path.' : 'Enter the feed brand name.');
                }
                if ($category) {
                    $path = array_map('trim', \ProductImport\Service\SourceConfigTransfer::splitPath($label));
                    foreach ($path as $segment) {
                        if ($segment === '' || mb_strlen($segment) > 191) {
                            throw new InvalidArgumentException('Write the feed category path as names (1 to 191 characters each) separated by " > ", for example "Sports > Sails".');
                        }
                    }
                    $created = $repository->createManual($idSource, (new \ProductImport\Service\CategoryPathNormalizer())->hash($path), json_encode($path, JSON_THROW_ON_ERROR), $id);
                } else {
                    $name = trim($label);
                    if (mb_strlen($name) > 191) {
                        throw new InvalidArgumentException('The brand name must not exceed 191 characters.');
                    }
                    $created = $repository->createManual($idSource, $name, $id);
                }
                if (!$created) {
                    throw new InvalidArgumentException($category ? 'This feed category is already in the list.' : 'This feed brand is already in the list.');
                }
            }
            $deleted = array_flip(array_filter((array) ($part['del'] ?? []), 'is_string'));
            foreach ((array) ($part['rows'] ?? []) as $key => $value) {
                if (isset($deleted[(string) $key])) {
                    continue;
                }
                if (!$repository->findOverride($idSource, (string) $key)) {
                    throw new InvalidArgumentException($unknown);
                }
                $repository->setOverride($idSource, (string) $key, $this->stagedDestination($value, $options, $idColumn, $category ? 'The category' : 'The brand'));
            }
            foreach (array_keys($deleted) as $key) {
                if (!$repository->findOverride($idSource, (string) $key)) {
                    throw new InvalidArgumentException($unknown);
                }
                $repository->delete($idSource, (string) $key);
            }
        }
        $part = $staged['feature'] ?? [];
        if (!is_array($part) || ($part['rules'] ?? []) === [] && ($part['del'] ?? []) === [] && ($part['add'] ?? []) === []) {
            return;
        }
        $options = $catalog->features((int) $source['id_lang_default']);
        $repository = new \ProductImport\Repository\FeatureMappingRepository();
        foreach ((array) ($part['add'] ?? []) as $add) {
            $name = is_array($add) && is_string($add['name'] ?? null) ? \ProductImport\Service\NativeFields::trimText($add['name']) : '';
            if ($name === '' || mb_strlen($name) > \ProductImport\Service\NativeFields::FEATURE_NAME_LIMIT) {
                throw new InvalidArgumentException('Enter the feed feature name (1 to ' . \ProductImport\Service\NativeFields::FEATURE_NAME_LIMIT . ' characters).');
            }
            if (!$repository->createManual($idSource, $name)) {
                throw new InvalidArgumentException('The feed feature "' . $name . '" is already in the list.');
            }
            if (($add['rule_action'] ?? 'none') !== 'none') {
                [$action, $idFeature, $createName, $expression] = $this->stagedFeatureRule($add, $name, $options);
                $repository->setRule($idSource, $name, $action, $idFeature, $createName, $expression);
            } elseif (is_string($add['value_expression'] ?? null) && trim($add['value_expression']) !== '') {
                [$action, $idFeature, $createName, $expression] = $this->stagedFeatureRule(['rule_action' => 'none'] + $add, $name, $options);
                $repository->setRule($idSource, $name, $action, $idFeature, $createName, $expression);
            }
        }
        $deleted = array_flip(array_filter((array) ($part['del'] ?? []), 'is_string'));
        foreach ((array) ($part['rules'] ?? []) as $key => $rule) {
            if (isset($deleted[(string) $key])) {
                continue;
            }
            if (!is_array($rule) || !$repository->findOverride($idSource, (string) $key)) {
                throw new InvalidArgumentException('Unknown feed feature.');
            }
            [$action, $idFeature, $createName, $expression] = $this->stagedFeatureRule($rule, (string) $key, $options);
            $repository->setRule($idSource, (string) $key, $action, $idFeature, $createName, $expression);
        }
        foreach (array_keys($deleted) as $key) {
            if (!$repository->findOverride($idSource, (string) $key)) {
                throw new InvalidArgumentException('Unknown feed feature.');
            }
            $repository->delete($idSource, (string) $key);
        }
    }

    /** @return array{0: string, 1: ?int, 2: ?string, 3: ?string} action, feature id, create name and value formula of one staged feature rule, validated */
    private function stagedFeatureRule(array $rule, string $key, array $options): array
    {
        $action = $rule['rule_action'] ?? null;
        if (!is_string($action) || !in_array($action, \ProductImport\Service\FeatureMapping::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid action for feed feature "' . $key . '".');
        }
        $idFeature = null;
        $createName = null;
        if ($action === \ProductImport\Service\FeatureMapping::MAP) {
            $idFeature = $this->stagedDestination($rule['feature'] ?? '', $options, 'id_feature', 'The feature');
            if ($idFeature === null) {
                throw new InvalidArgumentException('Choose an existing feature for "' . $key . '".');
            }
        } elseif ($action === \ProductImport\Service\FeatureMapping::CREATE) {
            $createName = is_string($rule['create_name'] ?? null) ? trim($rule['create_name']) : '';
            if ($createName === '' || mb_strlen($createName) > \ProductImport\Service\NativeFields::FEATURE_NAME_LIMIT || !\ProductImport\Service\NativeFields::isGenericName($createName)) {
                throw new InvalidArgumentException('Enter the name of the feature to create for "' . $key . '" (1 to ' . \ProductImport\Service\NativeFields::FEATURE_NAME_LIMIT . ' characters, without <>={}).');
            }
        }
        $expression = $rule['value_expression'] ?? '';
        if (!is_string($expression) || mb_strlen($expression) > 5000) {
            throw new InvalidArgumentException('The value formula of "' . $key . '" must be text of at most 5000 characters.');
        }
        $expression = trim($expression);
        if ($expression !== '' && ($problem = (new \ProductImport\Service\ExpressionEvaluator())->lint($expression, ['value', 'fields'])) !== null) {
            throw new InvalidArgumentException('Value formula of "' . $key . '": ' . $problem);
        }
        return [$action, $idFeature, $createName, $expression === '' ? null : $expression];
    }

    private function requireSource(string $permission): array
    {
        if (!$this->access($permission) || !$this->checkToken()) {
            throw new RuntimeException('Permission denied or invalid security token.');
        }
        $source = $this->sources->find((int) Tools::getValue('id_source'));
        if (!$source) {
            throw new InvalidArgumentException('Save a source first.');
        }
        return $source;
    }

    private function sendJson(array $result): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"error":"Unable to encode response"}');
    }

    public function initContent()
    {
        // Render explicitly: there is no source ObjectModel for AdminController to load.
        $this->content = $this->migrationReportHtml;
        $this->content .= $this->access('view') ? \ProductImport\Repository\SchemaStatus::alertHtml($this->access('edit') ? self::$currentIndex . '&token=' . $this->token : '') : '';
        if (Tools::isSubmit('addpi_source') || Tools::isSubmit('updatepi_source') || Tools::isSubmit('submitAddpi_source')) {
            if ($this->access((int) Tools::getValue('id_source') > 0 ? 'edit' : 'add')) {
                $this->content .= $this->renderForm();
                if ((int) Tools::getValue('id_source') > 0 && $this->access('edit')) {
                    $this->content .= $this->renderDeletedPanel((int) Tools::getValue('id_source'));
                }
            }
        } elseif ($this->access('view')) {
            if ($this->access('add')) {
                $url = $this->context->link->getAdminLink('AdminPiSource') . '&addpi_source';
                $this->content .= '<p><a class="btn btn-default" href="' . Tools::safeOutput($url) . '">+ ' . Tools::safeOutput($this->trans('Add source')) . '</a></p>';
            }
            $this->content .= $this->renderList();
        }
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign([
            'content' => $this->content,
            'show_page_header_toolbar' => false,
            'page_header_toolbar_title' => $this->trans('Product Import sources'),
        ]);
    }

    public function renderList()
    {
        $helper = new HelperList();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->title = $this->trans('Product Import sources');
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->simple_header = true;
        $helper->actions = [];
        foreach (['edit', 'delete'] as $action) {
            if ($this->access($action)) {
                $helper->actions[] = $action;
            }
        }
        if ($this->access('view')) {
            $helper->actions[] = 'export';
        }
        $cronUrl = $this->context->link->getModuleLink('productimport', 'cron', ['token' => Configuration::get('PIIMPORT_CRON_TOKEN')], true);
        $sources = $this->sources->findAll();
        $panel = '';
        if ($this->access('edit')) {
            $this->context->smarty->assign([
                'pi_run_url' => $this->context->link->getAdminLink('AdminPiSource'),
                'pi_run_sources' => $sources,
                'pi_run_baseline' => (new \ProductImport\Repository\ImportRunRepository())->maxId(),
                'pi_run_log_url' => $this->context->link->getAdminLink('AdminPiRunLog'),
            ]);
            $panel = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/run_import.tpl');
        }
        $importPanel = '';
        $canCreate = $this->access('add');
        $canOverwrite = $this->access('edit');
        if ($canCreate || $canOverwrite) {
            $this->context->smarty->assign([
                'pi_import_url' => $this->context->link->getAdminLink('AdminPiSource') . '&ajax=1&action=importSource',
                'pi_import_can_create' => $canCreate,
                'pi_import_can_overwrite' => $canOverwrite,
                'pi_import_sources' => $sources,
            ]);
            $importPanel = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/source_import.tpl');
        }

        $feedDirPanel = $this->renderFeedDirPanel();

        return $feedDirPanel . '<div class="alert alert-info">Daily cron URL: <code>' . Tools::safeOutput($cronUrl) . '</code> &mdash; append <code>&amp;source=&lt;ID&gt;</code> to import one specific source instead of all active ones (see the ID column below).</div>' . $panel . $helper->generateList($sources, [
            'id_source' => ['title' => $this->trans('ID')],
            'name' => ['title' => $this->trans('Name')],
            'technical_key' => ['title' => $this->trans('Technical key')],
            'active' => ['title' => $this->trans('Active'), 'type' => 'bool'],
        ]) . $importPanel;
    }

    private function locationHelp(): string
    {
        return Tools::safeOutput($this->trans('https://… to download, or a file path inside %dir% (e.g. products.json)', ['%dir%' => \ProductImport\Service\FeedLocation::feedDir() ?: '(import folder not configured)']));
    }

    private function migrateTables(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->access('edit') || !$this->checkToken()) {
            $this->errors[] = $this->trans('You do not have permission or your security token is invalid.');

            return;
        }
        $report = \ProductImport\Service\Upgrade\Upgrader::create()->run();
        // Self-heal: a failed hook registration in the upgrade file would otherwise leave manual deletes untracked.
        $module = Module::getInstanceByName('productimport');
        if ($report['ok'] && (!$module || !$module->registerHook(ProductImport::HOOKS))) {
            $report['ok'] = false;
            $report['error'] = $this->trans('The product delete hooks could not be registered.');
        }
        \ProductImport\Repository\SchemaStatus::reset();
        $lines = array_merge($report['statements'], $report['steps']);
        if ($lines !== []) {
            $this->migrationReportHtml = '<div class="alert alert-info"><ul><li><code>' . implode('</code></li><li><code>', array_map([Tools::class, 'safeOutput'], $lines)) . '</code></li></ul></div>';
        }
        if ($report['ok']) {
            $this->confirmations[] = $this->trans('The module data is up to date.');
        } else {
            $this->errors[] = (string) $report['error'];
        }
    }

    private function saveFeedDir(): void
    {
        if (!$this->access('edit') || !$this->checkToken()) {
            $this->errors[] = $this->trans('You do not have permission or your security token is invalid.');

            return;
        }
        $dir = Tools::getValue('feed_dir', '');
        $dir = is_string($dir) ? rtrim(trim($dir), '/') : '';
        if ($dir === '' || $dir[0] !== '/' || str_contains($dir, "\0") || in_array('..', explode('/', $dir), true)) {
            $this->errors[] = $this->trans('The import folder must be an absolute path without "..".');

            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->errors[] = $this->trans('The import folder does not exist and could not be created.');

            return;
        }
        $real = realpath($dir);
        if ($real === false || !Configuration::updateValue(\ProductImport\Service\FeedLocation::CONFIG_KEY, $real)) {
            $this->errors[] = $this->trans('Unable to save the import folder.');

            return;
        }
        $this->confirmations[] = $this->trans('Import folder saved.');
    }

    /** Tombstones: products someone deleted in the store. Restoring forgets the link so the next run creates the product fresh. */
    private function restoreDeleted(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->access('edit') || !$this->checkToken()) {
            $this->errors[] = $this->trans('You do not have permission or your security token is invalid.');

            return;
        }
        $idSource = (int) Tools::getValue('id_source');
        if ($idSource <= 0 || !$this->sources->find($idSource)) {
            $this->errors[] = $this->trans('Source not found.');

            return;
        }
        $externalId = Tools::getValue('restore_external_id', null);
        try {
            $links = new \ProductImport\Repository\ExternalProductRepository();
            if (Tools::getValue('restore_all') === '1') {
                $restored = $links->restoreTombstones($idSource);
            } elseif (is_string($externalId) && $externalId !== '') {
                $restored = $links->restoreTombstones($idSource, $externalId);
            } else {
                throw new InvalidArgumentException('Choose a deleted product to restore.');
            }
            $this->confirmations[] = sprintf($this->trans('%d product(s) will be created again by the next import run.'), $restored);
        } catch (Throwable $error) {
            $this->errors[] = Tools::safeOutput($error->getMessage());
        }
    }

    private function renderDeletedPanel(int $idSource): string
    {
        try {
            $rows = (new \ProductImport\Repository\ExternalProductRepository())->listTombstones($idSource);
        } catch (Throwable $error) {
            return '<div class="alert alert-warning">' . Tools::safeOutput($error->getMessage()) . '</div>';
        }
        $action = Tools::safeOutput(self::$currentIndex . '&token=' . $this->token . '&updatepi_source&id_source=' . $idSource);
        $html = '<div class="panel" id="pi-deleted-products"><h3>' . Tools::safeOutput($this->trans('Deleted products')) . '</h3>'
            . '<p class="help-block">' . Tools::safeOutput($this->trans('Products deleted in the store are skipped by the import. Restore forgets the link, so the next import run creates the product again from the feed. The new product gets a new ID and URL; fully restoring the old product needs a database backup.')) . '</p>';
        if ($rows === []) {
            return $html . '<p>' . Tools::safeOutput($this->trans('No deleted products.')) . '</p></div>';
        }
        $html .= '<table class="table"><thead><tr><th>' . Tools::safeOutput($this->trans('External ID')) . '</th><th>' . Tools::safeOutput($this->trans('Product')) . '</th><th>' . Tools::safeOutput($this->trans('Deleted')) . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $name = trim((string) ($row['deleted_name'] ?? '')) !== '' ? (string) $row['deleted_name'] : $externalId;
            $html .= '<tr><td>' . Tools::safeOutput($externalId) . '</td><td>' . Tools::safeOutput($name) . '</td><td>' . Tools::safeOutput((string) $row['date_deleted']) . '</td><td>'
                . '<form method="post" action="' . $action . '"><input type="hidden" name="restore_external_id" value="' . Tools::safeOutput($externalId) . '">'
                . '<button type="submit" name="submitPiRestore" value="1" class="btn btn-default btn-sm">' . Tools::safeOutput($this->trans('Restore')) . '</button></form></td></tr>';
        }

        return $html . '</tbody></table><form method="post" action="' . $action . '"><input type="hidden" name="restore_all" value="1">'
            . '<button type="submit" name="submitPiRestore" value="1" class="btn btn-default">' . Tools::safeOutput($this->trans('Restore all')) . '</button></form></div>';
    }

    private function renderFeedDirPanel(): string
    {
        if (!$this->access('edit')) {
            return '';
        }

        return '<form method="post" action="' . Tools::safeOutput(self::$currentIndex . '&token=' . $this->token) . '" class="panel form-inline">'
            . '<h3>' . Tools::safeOutput($this->trans('Import folder')) . '</h3>'
            . '<p class="help-block">' . Tools::safeOutput($this->trans('File sources must be inside this folder; relative paths resolve inside it.')) . '</p>'
            . '<input type="text" name="feed_dir" class="form-control" style="width:40em" value="' . Tools::safeOutput(\ProductImport\Service\FeedLocation::feedDir()) . '" aria-label="' . Tools::safeOutput($this->trans('Import folder')) . '"> '
            . '<button type="submit" name="submitPiFeedDir" value="1" class="btn btn-default">' . Tools::safeOutput($this->trans('Save')) . '</button></form>';
    }

    public function renderForm()
    {
        $id = (int) Tools::getValue('id_source');
        $source = $this->submittedSource;
        if ($source === null) {
            $source = $id > 0 ? $this->sources->find($id) : [];
        }
        if ($source === null) {
            $this->errors[] = $this->trans('Source not found.');

            return '';
        }
        $source += [
            'name' => '', 'technical_key' => '', 'location' => '', 'source_format' => 'json', 'xml_item_path' => '',
            'identifier_field' => '', 'filter_expression' => '', 'field_mapping' => '{}', 'active' => 1,
            'variant_mapping' => null, 'root_category_id' => null, 'id_supplier' => null, 'id_lang_default' => 1, 'deactivate_missing' => 0, 'delete_missing_days' => \ProductImport\Service\MissingLifecycle::DEFAULT_DELETE_DAYS,
        ];
        $source['source_format'] = \ProductImport\Service\Format\FeedFormats::has((string) $source['source_format']) ? $source['source_format'] : 'json';
        $mapping = json_decode($source['field_mapping'], true);
        $rows = $this->submittedMapping;
        if ($rows === null) {
            $rows = [];
            foreach (is_array($mapping) ? $mapping : [] as $target => $expression) {
                $rows[] = ['target' => (string) $target, 'expression' => $expression];
            }
        }
        $variant = $this->submittedVariant ?? json_decode($source['variant_mapping'] ?: '{}', true);
        $variant = is_array($variant) ? $variant : [];
        $variantFields = [];
        foreach ($variant['fields'] ?? [] as $target => $expression) {
            $variantFields[] = ['target' => (string) $target, 'expression' => $expression];
        }
        // The grid script rebuilds the field_mapping_*, variants_expression and variant_* POST fields from this state.
        $state = [
            'mapping' => array_values($rows),
            'variants_expression' => $variant['variants_expression'] ?? '',
            'attributes' => $variant['attribute_rows'] ?? array_map(static function ($row) {
                return ['target' => $row['name'], 'expression' => $row['expression']];
            }, $variant['attributes'] ?? []),
            'fields' => $variant['field_rows'] ?? $variantFields,
        ];
        $this->context->smarty->assign('pi_staged_json', $this->submittedStaged);
        $this->context->smarty->assign('pi_state_json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        $this->context->smarty->assign('pi_carriers_json', json_encode(array_column((new \ProductImport\Service\CatalogOptions())->carriers(), 'name'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]');
        $this->context->smarty->assign(['pi_can_discover' => $this->access('edit'), 'pi_preview_id' => $id, 'pi_preview_url' => $this->context->link->getAdminLink('AdminPiSource'), 'pi_run_log_url' => $this->context->link->getAdminLink('AdminPiRunLog'), 'pi_run_name' => $source['name'], 'pi_run_baseline' => (new \ProductImport\Repository\ImportRunRepository())->maxId(), 'pi_run_deactivate' => $source['deactivate_missing'], 'pi_can_run' => $this->access('edit')]);
        $testHtml = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/preview.tpl');
        $mappingHtml = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/source_form.tpl');
        $helpTemplate = $this->context->smarty->createTemplate(dirname(__DIR__, 3) . '/views/templates/admin/source_form.tpl');
        $helpTemplate->assign('pi_help_section', 'filter');
        $filterHelp = $helpTemplate->fetch();
        $inputs = [];
        foreach ([
            'name' => 'Name', 'technical_key' => 'Technical key', 'location' => 'Source', 'identifier_field' => 'Identifier field',
        ] as $name => $label) {
            if ($name === 'identifier_field') {
                $inputs[] = ['type' => 'html', 'name' => 'source_test', 'html_content' => $testHtml];
                $inputs[] = ['type' => 'html', 'name' => 'identifier_control', 'label' => $this->trans('Identifier field'), 'html_content' => '<select id="pi-identifier-picker" aria-label="Identifier field"><option value="">— choose —</option><option value="__other__">Other key…</option></select><input type="text" name="identifier_field" id="identifier_field" value="' . htmlspecialchars((string) $source['identifier_field'], ENT_QUOTES, 'UTF-8') . '" aria-label="Other identifier key" style="display:none"><p class="help-block">Choose a top-level JSON key or enter another key. Internal link between this source\'s feed items and PrestaShop products. It is not written to the product (it shows only as the supplier reference when the source has a supplier). Use the reference field for a visible product code.</p>'];
            }
            if ($name === 'identifier_field') {
                continue;
            }
            $inputs[] = [
                'type' => 'text', 'label' => $this->trans($label), 'name' => $name, 'id' => $name,
                'required' => in_array($name, ['name', 'technical_key', 'location'], true),
                'desc' => $name === 'location' ? $this->locationHelp() : '',
            ];
        }
        array_splice($inputs, 3, 0, [[
            'type' => 'select', 'name' => 'source_format', 'id' => 'source_format', 'label' => $this->trans('Format'),
            'options' => ['query' => array_map(static fn ($name, $label) => ['value' => $name, 'label' => $label], array_keys(\ProductImport\Service\Format\FeedFormats::choices()), \ProductImport\Service\Format\FeedFormats::choices()), 'id' => 'value', 'name' => 'label'],
        ], [
            'type' => 'text', 'name' => 'xml_item_path', 'id' => 'xml_item_path', 'label' => $this->trans('XML item path'),
            'desc' => $this->trans('Only for XML sources: the path from the XML root to each repeating product element, e.g. products/product.'),
        ]]);
        $inputs[] = ['type' => 'textarea', 'label' => $this->trans('Filter expression'), 'name' => 'filter_expression', 'desc' => $filterHelp];
        $inputs[] = ['type' => 'html', 'name' => 'mapping_rows', 'html_content' => $mappingHtml];
        $tree = new HelperTreeCategories('pi-root-category-tree', $this->trans('Root category'));
        $tree->setRootCategory((int) Configuration::get('PS_ROOT_CATEGORY'));
        $tree->setInputName('root_category_id');
        $tree->setUseCheckBox(false);
        $tree->setUseSearch(true);
        $tree->setSelectedCategories($source['root_category_id'] ? [(int) $source['root_category_id']] : []);
        $inputs[] = [
            'type' => 'html', 'name' => 'root_category_tree', 'label' => $this->trans('Root category'),
            'html_content' => $tree->render() . '<button type="button" class="btn btn-default" onclick="document.querySelectorAll(&quot;[name=root_category_id]&quot;).forEach(function (input) { input.checked = false; });">Use Home</button>',
            'desc' => $this->trans('Auto-created categories are placed under this category. With none selected they go under Home.'),
        ];
        $inputs[] = [
            'type' => 'select', 'name' => 'id_lang_default', 'label' => $this->trans('Source language'),
            'options' => ['query' => Language::getLanguages(false), 'id' => 'id_lang', 'name' => 'name'],
        ];
        $inputs[] = [
            'type' => 'select', 'name' => 'id_supplier', 'label' => $this->trans('Supplier'),
            'options' => ['query' => array_merge([['id_supplier' => '', 'label' => '— none —']], (new \ProductImport\Service\CatalogOptions())->suppliers()), 'id' => 'id_supplier', 'name' => 'label'],
            'desc' => $this->trans('Tags every product this source imports with this supplier, so you can tell at a glance which source it came from. Create suppliers under Catalog > Brands & Suppliers.'),
        ];
        foreach (['active' => 'Active', 'deactivate_missing' => 'Deactivate missing products'] as $field => $label) {
            $inputs[] = [
                'type' => 'switch', 'name' => $field, 'label' => $this->trans($label), 'is_bool' => true,
                'values' => [
                    ['id' => $field . '_on', 'value' => 1, 'label' => $this->trans('Yes')],
                    ['id' => $field . '_off', 'value' => 0, 'label' => $this->trans('No')],
                ],
                'desc' => $field === 'deactivate_missing' ? $this->trans('After a successful fetch, untouched products are deactivated and untouched combinations receive zero stock.') : '',
            ];
        }
        $inputs[] = [
            'type' => 'text', 'name' => 'delete_missing_days', 'id' => 'delete_missing_days', 'label' => $this->trans('Delete after (days)'), 'class' => 'fixed-width-sm', 'suffix' => $this->trans('days'),
            'desc' => $this->trans('A product that is missing from the feed is hidden and redirects (301) to its default category, unless you set a redirect of your own. When it has been missing for this many days it is deleted for good, only if it is still inactive and no other source links to it. Empty or 0 never deletes. Choose a period longer than your seasonal gaps: products that return after being deleted are created again with a new ID and URL. PrestaShop has no trash, so a deleted product can only be recovered from a database backup.'),
        ];
        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->id = $id;
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->submit_action = 'submitAddpi_source';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = $source;

        return $helper->generateForm([['form' => [
            'legend' => ['title' => $this->trans('Import source')],
            'input' => $inputs,
            'submit' => ['title' => $this->trans('Save')],
        ]]]);
    }

    private function readSubmission(): array
    {
        $data = [];
        foreach (['name', 'technical_key', 'location', 'identifier_field', 'filter_expression', 'xml_item_path'] as $field) {
            $value = Tools::getValue($field, '');
            if (!is_string($value)) {
                throw new InvalidArgumentException('Invalid value for ' . $field . '.');
            }
            $data[$field] = trim($value);
        }
        $format = Tools::getValue('source_format', 'json');
        if (!is_string($format) || !\ProductImport\Service\Format\FeedFormats::has($format)) {
            throw new InvalidArgumentException('Choose a supported source format.');
        }
        $data['source_format'] = $format;
        // Resolve only validates the location (scheme, import-folder containment); the file may not exist yet.
        \ProductImport\Service\FeedLocation::resolve($data['location']);
        $options = \ProductImport\Service\Format\FeedFormats::get($format)->options();
        foreach ($options as $option => $definition) {
            if ($definition['required'] && $data[$option] === '') {
                throw new InvalidArgumentException($definition['label'] . ' is required for ' . $format . ' sources.' . ($option === 'xml_item_path' ? ' Choose the detected item path or enter one.' : ''));
            }
        }
        if (!isset($options['xml_item_path'])) {
            $data['xml_item_path'] = null;
        }
        foreach (['active', 'deactivate_missing'] as $field) {
            $data[$field] = (int) (Tools::getValue($field) === '1');
        }
        $days = Tools::getValue('delete_missing_days', '');
        if (!is_string($days) || ($days !== '' && (!ctype_digit($days) || (int) $days > 36500))) {
            throw new InvalidArgumentException('Delete after must be a number of days from 0 to 36500, or empty.');
        }
        $data['delete_missing_days'] = \ProductImport\Service\MissingLifecycle::normalizeDays($days);
        $root = Tools::getValue('root_category_id', '');
        $supplier = Tools::getValue('id_supplier', '');
        $language = Tools::getValue('id_lang_default', '1');
        if (!is_string($root) || ($root !== '' && (!ctype_digit($root) || (int) $root <= 0))) {
            throw new InvalidArgumentException('Invalid root category ID.');
        }
        if (!is_string($language) || !ctype_digit($language) || (int) $language <= 0) {
            throw new InvalidArgumentException('Invalid source language ID.');
        }
        if (!is_string($supplier) || ($supplier !== '' && (!ctype_digit($supplier) || (int) $supplier <= 0))) {
            throw new InvalidArgumentException('Invalid supplier ID.');
        }
        $data['root_category_id'] = $root === '' ? null : (int) $root;
        $data['id_supplier'] = $supplier === '' ? null : (int) $supplier;
        $data['id_lang_default'] = (int) $language;
        $this->submittedSource = $data;
        $variantExpression = Tools::getValue('variants_expression', '');
        if (!is_string($variantExpression)) {
            throw new InvalidArgumentException('Invalid variants expression.');
        }
        $this->submittedVariant = ['variants_expression' => trim($variantExpression), 'attribute_rows' => [], 'field_rows' => []];
        $data['variant_mapping'] = null;
        if (trim($variantExpression) !== '') {
            $this->submittedVariant['attribute_rows'] = $this->readRows('variant_attribute');
            $this->submittedVariant['field_rows'] = $this->readRows('variant_field');
        }
        $targets = Tools::getValue('field_mapping_target', []);
        $expressions = Tools::getValue('field_mapping_expression', []);
        if (!is_array($targets) || !is_array($expressions) || array_keys($targets) !== array_keys($expressions)) {
            throw new InvalidArgumentException('Invalid mapping rows.');
        }
        $this->submittedMapping = [];
        foreach ($targets as $index => $target) {
            if (!is_string($target) || !is_string($expressions[$index])) {
                throw new InvalidArgumentException('Mapping rows must contain strings.');
            }
            $this->submittedMapping[] = ['target' => $target, 'expression' => $expressions[$index]];
        }
        $mapping = [];
        foreach ($this->submittedMapping as $row) {
            $target = trim($row['target']);
            $expression = trim($row['expression']);
            if ($target === '' && $expression === '') {
                continue;
            }
            if ($target === '' || $expression === '') {
                throw new InvalidArgumentException('Each mapping row needs a target and expression.');
            }
            if (array_key_exists($target, $mapping)) {
                throw new InvalidArgumentException('Duplicate mapping target: ' . $target);
            }
            $mapping[$target] = $expression;
        }
        if ($this->submittedVariant['variants_expression'] !== '') {
            $attributes = $this->rowsToMapping($this->submittedVariant['attribute_rows']);
            $fields = $this->rowsToMapping($this->submittedVariant['field_rows']);
            if ($attributes === [] || !isset($fields['reference'])) {
                throw new InvalidArgumentException('Variants require at least one attribute and a reference field mapping.');
            }
            $definitions = [];
            foreach ($attributes as $name => $expression) {
                $definitions[] = ['name' => (string) $name, 'expression' => $expression];
            }
            $data['variant_mapping'] = json_encode([
                'variants_expression' => $this->submittedVariant['variants_expression'],
                'attributes' => $definitions, 'fields' => (object) $fields,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($data['variant_mapping'] === false) {
                throw new InvalidArgumentException('Unable to encode variant mapping: ' . json_last_error_msg());
            }
        }
        if ($data['root_category_id'] !== null && !Validate::isLoadedObject(new Category($data['root_category_id']))) {
            throw new InvalidArgumentException('Selected root category does not exist.');
        }
        $languageIds = array_map('intval', array_column(Language::getLanguages(false), 'id_lang'));
        if (!in_array($data['id_lang_default'], $languageIds, true)) {
            throw new InvalidArgumentException('Selected source language does not exist.');
        }
        \ProductImport\Service\SourceConfigTransfer::validateSourceFields($data);
        $existing = $this->sources->findByTechnicalKey($data['technical_key']);
        if ($existing && (int) $existing['id_source'] !== (int) Tools::getValue('id_source')) {
            throw new InvalidArgumentException('Technical key is already in use.');
        }
        $data['field_mapping'] = json_encode((object) $mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data['field_mapping'] === false) {
            throw new InvalidArgumentException('Unable to encode field mapping: ' . json_last_error_msg());
        }
        foreach (['filter_expression'] as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function readRows(string $prefix): array
    {
        $targets = Tools::getValue($prefix . '_target', []);
        $expressions = Tools::getValue($prefix . '_expression', []);
        if (!is_array($targets) || !is_array($expressions) || array_keys($targets) !== array_keys($expressions)) {
            throw new InvalidArgumentException('Invalid variant mapping rows.');
        }
        $rows = [];
        foreach ($targets as $index => $target) {
            if (!is_string($target) || !is_string($expressions[$index])) {
                throw new InvalidArgumentException('Variant mapping rows must contain strings.');
            }
            $rows[] = ['target' => $target, 'expression' => $expressions[$index]];
        }

        return $rows;
    }

    private function rowsToMapping(array $rows): array
    {
        $mapping = [];
        foreach ($rows as $row) {
            $target = trim($row['target']);
            $expression = trim($row['expression']);
            if ($target === '' && $expression === '') {
                continue;
            }
            if ($target === '' || $expression === '' || array_key_exists($target, $mapping)) {
                throw new InvalidArgumentException('Variant rows require a unique name/target and an expression.');
            }
            $mapping[$target] = $expression;
        }

        return $mapping;
    }

    public function displayExportLink($token = null, $id = null, $name = null)
    {
        $href = $this->context->link->getAdminLink('AdminPiSource') . '&ajax=1&action=exportSource&id_source=' . (int) $id;

        return '<a class="btn btn-default" href="' . Tools::safeOutput($href) . '" title="' . Tools::safeOutput($this->trans('Export')) . '"><i class="icon-download"></i> ' . Tools::safeOutput($this->trans('Export')) . '</a>';
    }

    public function ajaxProcessExportSource()
    {
        try {
            if (!$this->access('view') || !$this->checkToken()) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $source = $this->sources->find((int) Tools::getValue('id_source'));
            if (!$source) {
                throw new InvalidArgumentException('Source not found.');
            }
            $idSource = (int) $source['id_source'];
            $idShop = (int) $this->context->shop->id;
            $idLang = (int) $source['id_lang_default'];
            $idRoot = (int) Configuration::get('PS_ROOT_CATEGORY');
            $categories = new \ProductImport\Repository\CategoryMappingRepository();
            $manufacturers = new \ProductImport\Repository\ManufacturerMappingRepository();
            $features = new \ProductImport\Repository\FeatureMappingRepository();
            $document = (new \ProductImport\Service\SourceConfigTransfer())->build(
                $source,
                $categories->findAllForSource($idSource),
                $manufacturers->findAllForSource($idSource),
                $features->findAllForSource($idSource),
                [
                    'language_iso' => static function (int $id) {
                        $iso = Language::getIsoById($id);

                        return $iso ? (string) $iso : null;
                    },
                    'category_path' => static function (int $id) use ($categories, $idRoot, $idShop, $idLang) {
                        return $categories->findNamePath($id, $idRoot, $idShop, $idLang);
                    },
                    'manufacturer_name' => static function (int $id) use ($manufacturers) {
                        return $manufacturers->findName($id);
                    },
                    'feature_name' => static function (int $id) use ($features, $idLang) {
                        return $features->findName($id, $idLang);
                    },
                    'supplier_name' => function (int $id) {
                        return $this->sources->findSupplierName($id);
                    },
                ],
                (string) $this->module->version,
                gmdate('c')
            );
            $yaml = \ProductImport\Service\SourceConfigTransfer::encode($document);
        } catch (Throwable $error) {
            $this->sendJson(['error' => $error->getMessage()]);
        }
        $file = preg_replace('/[^a-z0-9_-]+/', '_', (string) $source['technical_key']) . '.productimport.yaml';
        header('Content-Type: text/yaml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($yaml));
        die($yaml);
    }

    public function ajaxProcessImportSource()
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkToken()) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $overwrite = ($_POST['target'] ?? 'new') === 'overwrite';
            $existing = null;
            if ($overwrite) {
                if (!$this->access('edit')) {
                    throw new RuntimeException('Permission denied or invalid security token.');
                }
                $rawId = $_POST['id_source'] ?? '';
                $existing = is_string($rawId) && ctype_digit($rawId) ? $this->sources->find((int) $rawId) : null;
                if (!$existing) {
                    throw new InvalidArgumentException('Choose the source to overwrite.');
                }
            } elseif (!$this->access('add')) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $text = $this->readUploadedConfig();
            $transfer = new \ProductImport\Service\SourceConfigTransfer();
            $document = $transfer->parse($text, $overwrite);
            $categories = new \ProductImport\Repository\CategoryMappingRepository();
            $manufacturers = new \ProductImport\Repository\ManufacturerMappingRepository();
            $features = new \ProductImport\Repository\FeatureMappingRepository();
            $idShop = (int) $this->context->shop->id;
            $children = [];
            $lookups = [
                'language_id' => static function (string $iso) {
                    $id = (int) Language::getIdByIso($iso);

                    return $id > 0 ? $id : null;
                },
                'default_language_id' => static function () {
                    return (int) Configuration::get('PS_LANG_DEFAULT');
                },
                'root_category_id' => (int) Configuration::get('PS_ROOT_CATEGORY'),
                'category_children' => static function (int $parent, int $idLang) use ($categories, $idShop, &$children) {
                    return $children[$idLang][$parent] ?? ($children[$idLang][$parent] = $categories->findChildNames($parent, $idShop, $idLang));
                },
                'manufacturers' => static function (string $name) use ($manufacturers) {
                    return $manufacturers->findNamesLike($name);
                },
                'suppliers' => function (string $name) {
                    return $this->sources->findSupplierNamesLike($name);
                },
                'features' => static function (string $name, int $idLang) use ($features) {
                    return $features->findNamesLike($name, $idLang);
                },
            ];
            if ($overwrite) {
                $resolved = $transfer->resolveOverwrite($document, $existing, $lookups);
            } else {
                $resolved = $transfer->resolve($document, $lookups, function (string $key) {
                    return $this->sources->findByTechnicalKey($key) !== null;
                });
            }
            $normalizer = new \ProductImport\Service\CategoryPathNormalizer();
            $counts = ['categories_created' => 0, 'categories_updated' => 0, 'brands_created' => 0, 'brands_updated' => 0, 'features_created' => 0, 'features_updated' => 0];
            $db = Db::getInstance();
            $db->execute('START TRANSACTION');
            try {
                if ($overwrite) {
                    $idSource = (int) $existing['id_source'];
                    if ($resolved['source'] !== [] && !$this->sources->update($idSource, $resolved['source'])) {
                        throw new RuntimeException(\ProductImport\Repository\DbError::message('Unable to update the source'));
                    }
                } else {
                    $idSource = $this->sources->create($resolved['source']);
                }
                foreach ($resolved['category_mappings'] as $row) {
                    $hash = $normalizer->hash($row['source_path']);
                    $counts[$overwrite && $categories->findOverride($idSource, $hash) !== null ? 'categories_updated' : 'categories_created']++;
                    $categories->upsertWithOverride($idSource, $hash, json_encode($row['source_path'], JSON_THROW_ON_ERROR), $row['id_category']);
                }
                foreach ($resolved['brand_mappings'] as $row) {
                    $counts[$overwrite && $manufacturers->findOverride($idSource, $row['source_name']) !== null ? 'brands_updated' : 'brands_created']++;
                    $manufacturers->upsertWithOverride($idSource, $row['source_name'], $row['id_manufacturer']);
                }
                foreach ($resolved['feature_mappings'] as $row) {
                    $counts[$overwrite && $features->findOverride($idSource, $row['source_name']) !== null ? 'features_updated' : 'features_created']++;
                    $features->upsertWithRule($idSource, $row['source_name'], $row['action'], $row['id_feature'], $row['create_name'], $row['value_expression']);
                }
                $db->execute('COMMIT');
            } catch (Throwable $error) {
                $db->execute('ROLLBACK');
                throw $error;
            }
            $result = ['ok' => true, 'mode' => $overwrite ? 'overwrite' : 'new', 'id_source' => $idSource,
                'name' => $resolved['source']['name'] ?? $existing['name'],
                'technical_key' => $overwrite ? $existing['technical_key'] : $resolved['source']['technical_key'],
                'edit_url' => $this->context->link->getAdminLink('AdminPiSource') . '&updatepi_source&id_source=' . $idSource,
                'counts' => $counts, 'warnings' => $resolved['warnings']];
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    private function readUploadedConfig(): string
    {
        $limit = \ProductImport\Service\SourceConfigTransfer::MAX_BYTES;
        $file = $_FILES['source_file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (!is_string($file['tmp_name'] ?? null) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException(in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is larger than 1 MB.' : 'The file upload failed.');
            }
            // Read one byte past the limit so an oversized file is rejected without loading all of it.
            $json = file_get_contents($file['tmp_name'], false, null, 0, $limit + 1);
            if ($json === false) {
                throw new RuntimeException('Unable to read the uploaded file.');
            }

            return $json;
        }
        $pasted = $_POST['source_text'] ?? '';
        if (!is_string($pasted) || trim($pasted) === '') {
            throw new InvalidArgumentException('Choose a .productimport.yaml file or paste its content.');
        }

        return $pasted;
    }
}
