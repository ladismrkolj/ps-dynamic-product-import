<?php

namespace ProductImport\Service;

class ManufacturerResolver
{
    private \ProductImport\Repository\ManufacturerMappingRepository $mappings;
    /** @var array<string, int|null|array> Per-instance (per-run) results; commit mode is part of the key. */
    private array $cache = [];
    /** @var list<string> */
    private array $notices = [];

    public function __construct(?\ProductImport\Repository\ManufacturerMappingRepository $mappings = null)
    {
        $this->mappings = $mappings ?? new \ProductImport\Repository\ManufacturerMappingRepository();
    }

    public function discover(string $name, int $idSource): void
    {
        $name = trim($name);
        if ($name !== '') {
            if (mb_strlen($name) > 191) {
                throw new \InvalidArgumentException('Manufacturer name exceeds 191 characters.');
            }
            $this->mappings->upsertSeen($idSource, $name);
        }
    }

    /** Commit: ID/null; preview: {name, id_manufacturer, auto_create}. */
    public function resolve(?string $name, int $idSource, bool $commit = true, ?int $defaultId = null): int|array|null
    {
        if ($name === null || trim($name) === '') {
            return $commit ? null : ['name' => null, 'id_manufacturer' => null, 'auto_create' => false];
        }
        $name = trim($name);
        $key = self::cacheKey($idSource, $defaultId, $commit, $name);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $result = $this->lookup($name, $idSource, $commit, $defaultId);
        // A preview miss (manufacturer not yet created) can go stale once a commit creates it.
        if ($commit || (is_array($result) && $result['id_manufacturer'] !== null)) {
            $this->cache[$key] = $result;
        }

        return $result;
    }

    /** @return list<string> run-log lines about mappings repaired since the last call */
    public function drainNotices(): array
    {
        $notices = $this->notices;
        $this->notices = [];

        return $notices;
    }

    public static function cacheKey(int $idSource, ?int $defaultId, bool $commit, string $name): string
    {
        return $idSource . '|' . ($defaultId ?? 'n') . '|' . ($commit ? 'c' : 'p') . '|' . $name;
    }

    private function lookup(string $name, int $idSource, bool $commit, ?int $defaultId): int|array
    {
        // A name over the column length cannot have a row; it is still resolved, just never recorded or connected.
        $recordable = mb_strlen($name) <= 191;
        if ($commit && $recordable) {
            $this->mappings->upsertSeen($idSource, $name);
        }
        $row = $this->mappings->findOverride($idSource, $name);
        $mapped = $row !== null && $row['id_manufacturer'] !== null ? (int) $row['id_manufacturer'] : null;
        $plan = MappingResolution::plan($mapped, $mapped !== null && $this->mappings->manufacturerExists($mapped), $defaultId, $commit);
        if ($plan['decision'] === MappingResolution::OVERRIDE) {
            return $commit ? $mapped : ['name' => $name, 'id_manufacturer' => $mapped, 'auto_create' => false];
        }
        if ($plan['missing']) {
            $this->notices[] = 'brand "' . $name . '": mapped manufacturer #' . $mapped . ' no longer exists, mapping cleared and resolved again';
        }
        if ($plan['clear']) {
            $this->mappings->setOverride($idSource, $name, null);
        }
        if ($plan['decision'] === MappingResolution::DEFAULT) {
            return $commit ? $defaultId : ['name' => $name, 'id_manufacturer' => $defaultId, 'auto_create' => false];
        }
        $id = $this->mappings->findIdByName($name);
        if (!$commit) {
            return ['name' => $name, 'id_manufacturer' => $id, 'auto_create' => $id === null];
        }
        $id ??= $this->mappings->create($name);
        if ($plan['connect'] && $recordable) {
            $this->mappings->setOverride($idSource, $name, $id);
        }

        return $id;
    }
}
