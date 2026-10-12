<?php

namespace ProductImport\Service;

use ProductImport\Repository\FeatureMappingRepository;

/**
 * Turns the raw `features` value of a feed item into shop features through the source's mapping rows
 * (see FeatureMapping for the rules). A committed call records unseen names and connects created features;
 * a preview call never writes.
 */
class FeatureMapper
{
    public const SAMPLE_LIMIT = 5;
    private const ERROR_LIMIT = 20;

    private FeatureMappingRepository $mappings;
    private FeatureResolver $resolver;
    private ExpressionEvaluator $evaluator;
    /** @var array<int, array<string, array>> Per-instance (per-run) rows by source, then feed name. */
    private array $rules = [];
    /** @var array<int, array<string, true>> feed names already recorded as seen in this run */
    private array $seen = [];
    /** @var array<int, bool> */
    private array $exists = [];
    /** @var array<string, string> "id_lang|id_feature" => name, for the preview */
    private array $names = [];
    /** @var array<int, true> mapped features reported as gone, once per run */
    private array $reportedGone = [];

    public function __construct(FeatureMappingRepository $mappings, FeatureResolver $resolver, ExpressionEvaluator $evaluator)
    {
        $this->mappings = $mappings;
        $this->resolver = $resolver;
        $this->evaluator = $evaluator;
    }

    /**
     * @param mixed $features the value of the `features` field: a {name: value} map or a list of {name, value}
     * @param array $item the feed item, the `fields` variable of the value formulas
     *
     * @return array{rows: list<array{id_feature:int,id_feature_value:int}>, pairs: list<array{0:string,1:string}>, ignored: list<string>, unmapped: list<string>, warnings: list<string>}
     *               rows: committed features; pairs: the features as [shop feature name, value] (preview); ignored: names with the action
     *               ignore; unmapped: names the source setting left out; warnings: log lines
     *
     * @throws \InvalidArgumentException when $features is neither a map nor a list of {name, value}
     */
    public function resolve(mixed $features, array $item, int $idSource, string $unmapped, int $idLang, bool $commit): array
    {
        $normalized = NativeFields::features($features);
        $warnings = $normalized['warnings'];
        $pairs = $normalized['pairs'];
        $rules = $this->rules($idSource);
        foreach (array_keys($pairs) as $name) {
            $name = (string) $name;
            if ($commit && !isset($this->seen[$idSource][$name])) {
                $this->mappings->upsertSeen($idSource, $name);
                $this->seen[$idSource][$name] = true;
                $rules[$name] ??= ['action' => FeatureMapping::NONE];
                $this->rules[$idSource][$name] = $rules[$name];
            }
            $rule = FeatureMapping::effective($rules[$name] ?? null);
            if ($rule['action'] === FeatureMapping::MAP && !$this->featureExists($rule['id_feature'])) {
                if ($commit && !isset($this->reportedGone[$idSource . '|' . $rule['id_feature']])) {
                    $this->reportedGone[$idSource . '|' . $rule['id_feature']] = true;
                    $warnings[] = '"' . $name . '": mapped feature #' . $rule['id_feature'] . ' no longer exists, mapping cleared';
                }
                if ($commit) {
                    $this->mappings->setAction($idSource, $name, FeatureMapping::NONE, null, null);
                }
                $rules[$name] = ['action' => FeatureMapping::NONE, 'value_expression' => $rules[$name]['value_expression'] ?? null];
                $this->rules[$idSource][$name] = $rules[$name];
            }
        }
        $plan = FeatureMapping::plan(
            $pairs,
            $rules,
            $unmapped,
            fn (string $name, string $expression, string $value): mixed => $this->evaluator->evaluate($expression, ['value' => $value, 'fields' => $item])
        );
        foreach ($plan['errors'] as $error) {
            $warnings[] = 'value formula of ' . $error;
        }
        $conflicts = $plan['conflicts'];
        $result = ['rows' => [], 'pairs' => [], 'ignored' => $plan['ignored'], 'unmapped' => $plan['unmapped']];
        $taken = [];
        foreach ($plan['entries'] as $entry) {
            if (!$commit) {
                // The target a commit would resolve to: an existing feature of the create name is the same feature as `map #N`.
                $target = $entry['id_feature'] ?? $this->mappings->findIdByName((string) $entry['create'], $idLang);
                $key = $target !== null ? 'id:' . $target : 'name:' . $entry['create'];
                if (isset($taken[$key])) {
                    $conflicts[] = 'feed names "' . $taken[$key] . '" and "' . $entry['name'] . '" both map to the same feature; "' . $taken[$key] . '" is used';
                    continue;
                }
                $taken[$key] = $entry['name'];
                $result['pairs'][] = [$target !== null ? $this->featureName($target, $idLang) : (string) $entry['create'], $entry['value']];
                continue;
            }
            try {
                $row = $entry['id_feature'] !== null
                    ? $this->resolver->resolveById($entry['id_feature'], $entry['value'], $idLang)
                    : $this->resolver->resolve((string) $entry['create'], $entry['value'], $idLang);
            } catch (\InvalidArgumentException $error) {
                $warnings[] = '"' . $entry['name'] . '": ' . $error->getMessage() . ', skipped';
                continue;
            }
            if ($entry['connect']) {
                $this->mappings->connect($idSource, $entry['name'], $row['id_feature']);
                $this->rules[$idSource][$entry['name']] = ['action' => FeatureMapping::MAP, 'id_feature' => $row['id_feature'], 'value_expression' => $rules[$entry['name']]['value_expression'] ?? null];
            }
            if (isset($taken[$row['id_feature']])) {
                $conflicts[] = 'feed names "' . $taken[$row['id_feature']] . '" and "' . $entry['name'] . '" both map to the feature #' . $row['id_feature'] . '; "' . $taken[$row['id_feature']] . '" is used';
                continue;
            }
            $taken[$row['id_feature']] = $entry['name'];
            $result['rows'][] = $row;
        }
        if ($conflicts !== []) {
            $warnings[] = 'several feed names map to one feature: ' . implode('; ', $conflicts);
        }
        $result['warnings'] = $warnings;

        return $result;
    }

    /**
     * Records the feature names of the whole feed (the filter is ignored) with their product counts and sample values.
     *
     * @return array{unique_features:int,items_scanned:int,failed_items:int,errors:list<string>,errors_omitted:int}
     */
    public function discover(array $items, int $idSource, string $expression): array
    {
        $found = [];
        $failed = 0;
        $errors = [];
        foreach ($items as $index => $item) {
            try {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Item is not an object.');
                }
                $value = $this->evaluator->evaluate($expression, ['fields' => $item]);
                if ($value === null) {
                    continue;
                }
                foreach (NativeFields::features($value)['pairs'] as $name => $text) {
                    $name = (string) $name;
                    $found[$name] ??= ['count' => 0, 'samples' => []];
                    ++$found[$name]['count'];
                    if (count($found[$name]['samples']) < self::SAMPLE_LIMIT && !in_array($text, $found[$name]['samples'], true)) {
                        $found[$name]['samples'][] = $text;
                    }
                }
            } catch (\Throwable $error) {
                ++$failed;
                if (count($errors) < self::ERROR_LIMIT) {
                    $errors[] = 'Item ' . $index . ': ' . mb_substr($error->getMessage(), 0, 500);
                }
            }
        }
        $this->mappings->recordDiscovery($idSource, $found);

        return ['unique_features' => count($found), 'items_scanned' => count($items), 'failed_items' => $failed, 'errors' => $errors,
            'errors_omitted' => max(0, $failed - count($errors))];
    }

    /** @return array<string, array> the source's rows by feed name */
    private function rules(int $idSource): array
    {
        if (!isset($this->rules[$idSource])) {
            $this->rules[$idSource] = array_column($this->mappings->findAllForSource($idSource), null, 'source_name');
        }

        return $this->rules[$idSource];
    }

    private function featureExists(int $idFeature): bool
    {
        return $this->exists[$idFeature] ??= $this->mappings->featureExists($idFeature);
    }

    private function featureName(int $idFeature, int $idLang): string
    {
        return $this->names[$idLang . '|' . $idFeature] ??= ($this->mappings->findName($idFeature, $idLang) ?? '#' . $idFeature);
    }
}
