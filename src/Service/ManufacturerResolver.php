<?php

namespace ProductImport\Service;

class ManufacturerResolver
{
    private \ProductImport\Repository\ManufacturerMappingRepository $mappings;
    /** @var array<string, int|null|array> Per-instance (per-run) results; commit mode is part of the key. */
    private array $cache = [];

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

    /** @return int|null|array Commit: ID/null; preview: {name, id_manufacturer, auto_create}. */
    public function resolve(?string $name, int $idSource, bool $commit = true, ?int $defaultId = null)
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

    public static function cacheKey(int $idSource, ?int $defaultId, bool $commit, string $name): string
    {
        return $idSource . '|' . ($defaultId ?? 'n') . '|' . ($commit ? 'c' : 'p') . '|' . $name;
    }

    /** @return int|array */
    private function lookup(string $name, int $idSource, bool $commit, ?int $defaultId)
    {
        $override = $this->mappings->findOverride($idSource, $name);
        if ($override !== null && $override['id_manufacturer'] !== null) {
            $id = (int) $override['id_manufacturer'];
            return $commit ? $id : ['name' => $name, 'id_manufacturer' => $id, 'auto_create' => false];
        }
        if ($defaultId !== null) {
            return $commit ? $defaultId : ['name' => $name, 'id_manufacturer' => $defaultId, 'auto_create' => false];
        }
        // Manufacturer names are in manufacturer itself, not manufacturer_lang.
        $id = \Db::getInstance()->getValue(
            'SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . "manufacturer` WHERE BINARY `name` = '" . pSQL($name, true) . "' ORDER BY `id_manufacturer`"
        );
        if (!$commit) {
            return ['name' => $name, 'id_manufacturer' => $id === false ? null : (int) $id, 'auto_create' => $id === false];
        }
        if ($id !== false) {
            return (int) $id;
        }
        $manufacturer = new \Manufacturer();
        $manufacturer->name = $name;
        $manufacturer->active = true;
        if (!$manufacturer->add()) {
            throw new \RuntimeException('Unable to create manufacturer: ' . $name);
        }

        return (int) $manufacturer->id;
    }
}
