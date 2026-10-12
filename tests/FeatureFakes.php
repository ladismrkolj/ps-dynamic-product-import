<?php

namespace ProductImport\Tests;

use ProductImport\Repository\FeatureMappingRepository;
use ProductImport\Service\FeatureResolver;

/** In-memory pi_feature_mapping plus the shop's features, for tests of the feature mapping. */
class FakeFeatureMappings extends FeatureMappingRepository
{
    /** @var array<string,array> rows by feed name */
    public array $rows = [];
    /** @var list<int> ids of the features that exist in the shop */
    public array $existing = [5, 6];
    /** @var array<int,string> shop feature names */
    public array $names = [5 => 'Fin box', 6 => 'Weight'];
    /** @var list<string> */
    public array $writes = [];
    public array $discovered = [];

    public static function row(string $name, string $action = 'none', ?int $idFeature = null, ?string $createName = null, ?string $expression = null): array
    {
        return ['source_name' => $name, 'action' => $action, 'id_feature' => $idFeature, 'create_name' => $createName, 'value_expression' => $expression];
    }

    public function findAllForSource(int $idSource): array
    {
        return array_values($this->rows);
    }

    public function upsertSeen(int $idSource, string $name): void
    {
        $this->writes[] = 'seen:' . $name;
        $this->rows[$name] ??= self::row($name);
    }

    public function setAction(int $idSource, string $name, string $action, ?int $idFeature, ?string $createName): void
    {
        $this->writes[] = 'action:' . $name . ':' . $action;
        $this->rows[$name] = ['action' => $action, 'id_feature' => $idFeature, 'create_name' => $createName] + $this->rows[$name];
    }

    public function connect(int $idSource, string $name, int $idFeature): void
    {
        $this->writes[] = 'connect:' . $name . ':' . $idFeature;
        $this->rows[$name] = ['action' => 'map', 'id_feature' => $idFeature, 'create_name' => null] + $this->rows[$name];
    }

    public function recordDiscovery(int $idSource, array $found): void
    {
        $this->discovered = $found;
    }

    public function featureExists(int $idFeature): bool
    {
        return in_array($idFeature, $this->existing, true);
    }

    public function findIdByName(string $name, int $idLang): ?int
    {
        $id = array_search($name, $this->names, true);

        return $id === false ? null : (int) $id;
    }

    public function findName(int $idFeature, int $idLang): ?string
    {
        return $this->names[$idFeature] ?? null;
    }
}

/** Creates features by name when missing and gives every (feature, value) pair a stable id; records the calls. */
class FakeFeatureResolver extends FeatureResolver
{
    /** @var array<string,int> feature name => id */
    public array $byName = ['Fin box' => 5];
    /** @var list<array> */
    public array $calls = [];
    /** @var array<string,int> "id_feature|value" => id_feature_value */
    private array $valueIds = [];

    public function resolve(string $name, string $value, int $idLang): array
    {
        if ($value === 'bad<value>') {
            throw new \InvalidArgumentException('feature value contains characters PrestaShop rejects (<>={})');
        }
        $this->byName[$name] ??= 100 + count($this->byName);

        return $this->done([$name, $value, $idLang], $this->byName[$name]);
    }

    public function resolveById(int $idFeature, string $value, int $idLang): array
    {
        return $this->done([$idFeature, $value, $idLang], $idFeature);
    }

    private function done(array $call, int $idFeature): array
    {
        $this->calls[] = $call;
        $key = $idFeature . '|' . $call[1];
        $this->valueIds[$key] ??= 1001 + count($this->valueIds);

        return ['id_feature' => $idFeature, 'id_feature_value' => $this->valueIds[$key]];
    }
}
