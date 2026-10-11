<?php

namespace ProductImport\Repository;

class CategoryMappingRepository
{
    /** @return list<array> every mapping row of the source (including `date_seen`, NULL for rows not seen in a feed yet) */
    public function findAllForSource(int $idSource): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_category_mapping` WHERE `id_source` = ' . (int) $idSource . ' ORDER BY `id_mapping`');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to list category mappings'));
        }
        return $rows;
    }

    public function findOverride(int $idSource, string $hash): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'pi_category_mapping` WHERE `id_source` = ' . (int) $idSource
            . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        );

        return $row ?: null;
    }

    public function upsertSeen(int $idSource, string $hash, string $sourcePath): void
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_category_mapping` (`id_source`, `source_path_hash`, `source_path`, `date_seen`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($hash) . "', '" . pSQL($sourcePath, true) . "', '" . $now . "', '" . $now
            . "') ON DUPLICATE KEY UPDATE `date_seen` = VALUES(`date_seen`), `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException(DbError::message('Unable to record category path'));
        }
    }

    /** Adds a row by hand (not seen in a feed yet). Returns false when the source already has that path. */
    public function createManual(int $idSource, string $hash, string $sourcePath, ?int $idCategory): bool
    {
        $db = \Db::getInstance();
        if (!$db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'pi_category_mapping` (`id_source`, `source_path_hash`, `source_path`, `id_category`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($hash) . "', '" . pSQL($sourcePath, true) . "', " . ($idCategory === null ? 'NULL' : (int) $idCategory)
            . ", '" . pSQL(date('Y-m-d H:i:s')) . "')"
        )) {
            throw new \RuntimeException(DbError::message('Unable to add category mapping'));
        }

        return $db->Affected_Rows() > 0;
    }

    /** Deletes one row of the given source only. */
    public function delete(int $idSource, string $hash): void
    {
        if (!\Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'pi_category_mapping` WHERE `id_source` = ' . (int) $idSource . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        )) {
            throw new \RuntimeException(DbError::message('Unable to delete category mapping'));
        }
    }

    public function setOverride(int $idSource, string $hash, ?int $idCategory): void
    {
        // Only discovered paths can be edited: the hash alone cannot recover source_path.
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_category_mapping` SET `id_category` = '
            . ($idCategory === null ? 'NULL' : (int) $idCategory)
            . ", `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "' WHERE `id_source` = " . (int) $idSource
            . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        )) {
            throw new \RuntimeException(DbError::message('Unable to save category override'));
        }
    }

    /** Remembers the category the name walk produced for this feed path (`id_category_auto`). Scoped by source. */
    public function setAuto(int $idSource, string $hash, int $idCategory): void
    {
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_category_mapping` SET `id_category_auto` = ' . (int) $idCategory
            . ' WHERE `id_source` = ' . (int) $idSource . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        )) {
            throw new \RuntimeException(DbError::message('Unable to remember auto category'));
        }
    }

    /** Forgets the remembered category so the next import walks the name path again. Scoped by source. */
    public function clearAuto(int $idSource, string $hash): void
    {
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_category_mapping` SET `id_category_auto` = NULL WHERE `id_source` = ' . (int) $idSource
            . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        )) {
            throw new \RuntimeException(DbError::message('Unable to clear auto category'));
        }
    }

    /** Insert or replace one mapping row with its override (used when importing a source configuration). */
    public function upsertWithOverride(int $idSource, string $hash, string $sourcePath, ?int $idCategory): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_category_mapping` (`id_source`, `source_path_hash`, `source_path`, `id_category`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($hash) . "', '" . pSQL($sourcePath, true) . "', " . ($idCategory === null ? 'NULL' : (int) $idCategory)
            . ", '" . pSQL(date('Y-m-d H:i:s')) . "') ON DUPLICATE KEY UPDATE `id_category` = VALUES(`id_category`), `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException(DbError::message('Unable to save imported category mapping'));
        }
    }

    /**
     * Names of the categories directly under $idParent in the shop and language.
     *
     * @return array<int,string> id_category => name
     */
    public function findChildNames(int $idParent, int $idShop, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS('SELECT c.`id_category`, cl.`name` FROM `' . _DB_PREFIX_ . 'category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $idShop
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = cs.`id_shop` AND cl.`id_lang` = ' . (int) $idLang
            . ' WHERE c.`id_parent` = ' . (int) $idParent . ' ORDER BY c.`id_category`');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to list child categories'));
        }
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id_category']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Name path from below the shop root category down to $idCategory, or null when it is missing,
     * unnamed or not below the root.
     *
     * @return list<string>|null
     */
    public function findNamePath(int $idCategory, int $idRoot, int $idShop, int $idLang): ?array
    {
        $path = [];
        $current = $idCategory;
        for ($guard = 0; $guard < 50 && $current !== $idRoot; ++$guard) {
            $row = \Db::getInstance()->getRow('SELECT c.`id_parent`, cl.`name` FROM `' . _DB_PREFIX_ . 'category` c'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $idShop
                . ' INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = cs.`id_shop` AND cl.`id_lang` = ' . (int) $idLang
                . ' WHERE c.`id_category` = ' . (int) $current);
            if (!$row || (string) $row['name'] === '') {
                return null;
            }
            array_unshift($path, (string) $row['name']);
            $current = (int) $row['id_parent'];
        }

        return $current === $idRoot && $path !== [] ? $path : null;
    }
}
