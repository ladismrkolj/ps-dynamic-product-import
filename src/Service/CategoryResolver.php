<?php

namespace ProductImport\Service;

use ProductImport\Repository\CategoryMappingRepository;

class CategoryResolver
{
    private $mappings;
    private $normalizer;
    /** @var array<string, int|array> Per-instance (per-run) results; commit mode is part of the key. */
    private array $cache = [];
    /** @var list<string> */
    private array $notices = [];

    public function __construct(CategoryMappingRepository $mappings, CategoryPathNormalizer $normalizer)
    {
        $this->mappings = $mappings;
        $this->normalizer = $normalizer;
    }

    /**
     * @param list<list<string>> $normalizedPaths
     * @return array Commit: list<int>; preview: list<{path, id_category, auto_create}>.
     */
    public function resolve(array $normalizedPaths, int $idSource, ?int $rootCategoryId, bool $commit = true, ?int $defaultId = null): array
    {
        $resolved = [];
        foreach ($normalizedPaths as $path) {
            if ($path === []) {
                continue;
            }
            $hash = $this->normalizer->hash($path);
            $key = self::cacheKey($idSource, $rootCategoryId, $defaultId, $commit, $hash);
            if (array_key_exists($key, $this->cache)) {
                $resolved[] = $this->cache[$key];
                continue;
            }
            if ($commit) {
                $this->mappings->upsertSeen($idSource, $hash, json_encode($path));
            }
            $row = $this->mappings->findOverride($idSource, $hash);
            $mapped = $row !== null && $row['id_category'] !== null ? (int) $row['id_category'] : null;
            $plan = MappingResolution::plan($mapped, $mapped !== null && $this->mappings->categoryExists($mapped), $defaultId, $commit);
            if ($plan['decision'] === MappingResolution::OVERRIDE) {
                $resolved[] = $this->cache[$key] = $commit ? $mapped : ['path' => $path, 'id_category' => $mapped, 'auto_create' => false];
                continue;
            }
            if ($plan['missing']) {
                $this->notices[] = 'category "' . implode(' > ', $path) . '": mapped category #' . $mapped . ' no longer exists, mapping cleared and resolved again';
            }
            if ($plan['clear']) {
                $this->mappings->setOverride($idSource, $hash, null);
            }
            if ($plan['decision'] === MappingResolution::DEFAULT) {
                $resolved[] = $this->cache[$key] = $commit ? $defaultId : ['path' => $path, 'id_category' => $defaultId, 'auto_create' => false];
                continue;
            }
            $parent = $rootCategoryId ?? (int) \Configuration::get('PS_HOME_CATEGORY');
            if ($parent <= 0 || !$this->mappings->categoryExists($parent)) {
                throw new \RuntimeException('Category root does not exist.');
            }
            foreach ($path as $segment) {
                $parent = $this->resolveChild($parent, $segment, $commit);
                if ($parent === null) {
                    break;
                }
            }
            if ($plan['connect']) {
                $this->mappings->setOverride($idSource, $hash, $parent);
            }
            $entry = $commit ? $parent : ['path' => $path, 'id_category' => $parent, 'auto_create' => $parent === null];
            // A preview miss (category not yet created) can go stale once a commit creates it.
            if ($commit || $parent !== null) {
                $this->cache[$key] = $entry;
            }
            $resolved[] = $entry;
        }

        return $resolved;
    }

    /** @return list<string> run-log lines about mappings repaired since the last call */
    public function drainNotices(): array
    {
        $notices = $this->notices;
        $this->notices = [];

        return $notices;
    }

    public static function cacheKey(int $idSource, ?int $rootCategoryId, ?int $defaultId, bool $commit, string $hash): string
    {
        return $idSource . '|' . ($rootCategoryId ?? 'n') . '|' . ($defaultId ?? 'n') . '|' . ($commit ? 'c' : 'p') . '|' . $hash;
    }

    /** Record paths only: never look up overrides or create catalog categories. */
    public function discover(array $normalizedPaths, int $idSource): void
    {
        foreach ($normalizedPaths as $path) {
            if ($path !== []) {
                $this->mappings->upsertSeen($idSource, $this->normalizer->hash($path), json_encode($path, JSON_THROW_ON_ERROR));
            }
        }
    }

    protected function resolveChild(int $parent, string $name, bool $commit): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT c.`id_category` FROM `' . _DB_PREFIX_ . 'category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category`'
            . ' AND cl.`id_shop` = c.`id_shop_default`'
            . ' WHERE c.`id_parent` = ' . (int) $parent . " AND BINARY cl.`name` = '" . pSQL($name, true) . "'"
            . ' ORDER BY c.`id_category` ASC'
        );
        if ($id !== false) {
            return (int) $id;
        }
        if (!$commit) {
            return null;
        }
        $category = new \Category();
        $category->id_parent = $parent;
        $category->id_shop_default = (int) \Context::getContext()->shop->id;
        $category->active = true;
        foreach (\Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $category->name[$idLang] = $name;
            $category->link_rewrite[$idLang] = \Tools::str2url($name) ?: 'category';
        }
        if (!$category->add()) {
            throw new \RuntimeException('Unable to create category: ' . $name);
        }

        return (int) $category->id;
    }
}
