<?php

namespace ProductImport\Repository;

/** pi_feature_mapping: what a source does with each feed feature name; plus the shop-side lookups the mapping needs. */
class FeatureMappingRepository
{
    private const TABLE = 'pi_feature_mapping';

    /** @return list<array> every row of the source, oldest first */
    public function findAllForSource(int $idSource): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_source` = ' . (int) $idSource . ' ORDER BY `id_mapping`');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to list feature mappings'));
        }

        return $rows;
    }

    public function findOverride(int $idSource, string $name): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_source` = ' . (int) $idSource . " AND `source_name` = '" . pSQL($name, true) . "'"
        );

        return $row ?: null;
    }

    /** Records that an import saw this feed name (a new name becomes a row with no mapping). */
    public function upsertSeen(int $idSource, string $name): void
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_source`, `source_name`, `date_seen`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($name, true) . "', '" . $now . "', '" . $now
            . "') ON DUPLICATE KEY UPDATE `date_seen` = VALUES(`date_seen`)"
        )) {
            throw new \RuntimeException(DbError::message('Unable to record feature name'));
        }
    }

    /**
     * Stores the result of a discovery scan of the whole feed: for every name the number of products and some sample values.
     *
     * @param array<string,array{count:int,samples:list<string>}> $found
     */
    public function recordDiscovery(int $idSource, array $found): void
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        foreach ($found as $name => $info) {
            if (!\Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_source`, `source_name`, `product_count`, `sample_values`, `date_seen`, `date_upd`) VALUES ('
                . (int) $idSource . ", '" . pSQL((string) $name, true) . "', " . (int) $info['count'] . ", '" . pSQL(json_encode($info['samples'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true)
                . "', '" . $now . "', '" . $now . "') ON DUPLICATE KEY UPDATE `product_count` = VALUES(`product_count`), `sample_values` = VALUES(`sample_values`), `date_seen` = VALUES(`date_seen`)"
            )) {
                throw new \RuntimeException(DbError::message('Unable to record discovered features'));
            }
        }
    }

    /** Sets the action of an existing row. $idFeature belongs to map, $createName to create. */
    public function setAction(int $idSource, string $name, string $action, ?int $idFeature, ?string $createName): void
    {
        $this->update($idSource, $name, '`action` = \'' . pSQL($action) . '\', `id_feature` = ' . ($idFeature === null ? 'NULL' : (int) $idFeature)
            . ', `create_name` = ' . ($createName === null ? 'NULL' : "'" . pSQL($createName, true) . "'"));
    }

    /** Sets action, target and value formula in one statement. */
    public function setRule(int $idSource, string $name, string $action, ?int $idFeature, ?string $createName, ?string $expression): void
    {
        $this->update($idSource, $name, '`action` = \'' . pSQL($action) . '\', `id_feature` = ' . ($idFeature === null ? 'NULL' : (int) $idFeature)
            . ', `create_name` = ' . ($createName === null ? 'NULL' : "'" . pSQL($createName, true) . "'")
            . ', `value_expression` = ' . ($expression === null ? 'NULL' : "'" . pSQL($expression, true) . "'"));
    }

    /** Lowest id of the feature with exactly this name in the language, or null. */
    public function findIdByName(string $name, int $idLang): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT `id_feature` FROM `' . _DB_PREFIX_ . 'feature_lang` WHERE `id_lang` = ' . (int) $idLang . " AND BINARY `name` = '" . pSQL($name) . "' ORDER BY `id_feature`"
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    public function setValueExpression(int $idSource, string $name, ?string $expression): void
    {
        $this->update($idSource, $name, '`value_expression` = ' . ($expression === null ? 'NULL' : "'" . pSQL($expression, true) . "'"));
    }

    /** A feature created for a `create` row is now the row's mapping. */
    public function connect(int $idSource, string $name, int $idFeature): void
    {
        $this->setAction($idSource, $name, 'map', $idFeature, null);
    }

    private function update(int $idSource, string $name, string $assignments): void
    {
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET ' . $assignments . ", `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "' WHERE `id_source` = " . (int) $idSource
            . " AND `source_name` = '" . pSQL($name, true) . "'"
        )) {
            throw new \RuntimeException(DbError::message('Unable to save feature mapping'));
        }
    }

    /** Insert or replace one row's action and value formula (importing a source configuration). */
    public function upsertWithRule(int $idSource, string $name, string $action, ?int $idFeature, ?string $createName, ?string $expression): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_source`, `source_name`, `action`, `id_feature`, `create_name`, `value_expression`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($name, true) . "', '" . pSQL($action) . "', " . ($idFeature === null ? 'NULL' : (int) $idFeature) . ', '
            . ($createName === null ? 'NULL' : "'" . pSQL($createName, true) . "'") . ', ' . ($expression === null ? 'NULL' : "'" . pSQL($expression, true) . "'")
            . ", '" . pSQL(date('Y-m-d H:i:s')) . "') ON DUPLICATE KEY UPDATE `action` = VALUES(`action`), `id_feature` = VALUES(`id_feature`),"
            . ' `create_name` = VALUES(`create_name`), `value_expression` = VALUES(`value_expression`), `date_upd` = VALUES(`date_upd`)'
        )) {
            throw new \RuntimeException(DbError::message('Unable to save imported feature mapping'));
        }
    }

    /** Deletes one row of the given source only. */
    public function delete(int $idSource, string $name): void
    {
        if (!\Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_source` = ' . (int) $idSource . " AND `source_name` = '" . pSQL($name, true) . "'"
        )) {
            throw new \RuntimeException(DbError::message('Unable to delete feature mapping'));
        }
    }

    /** Throws on a database error, so a failed query is never read as "deleted". */
    public function featureExists(int $idFeature): bool
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_feature` FROM `' . _DB_PREFIX_ . 'feature` WHERE `id_feature` = ' . (int) $idFeature);
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to check feature'));
        }

        return $rows !== [];
    }

    /** @return string|null the feature's name in the language */
    public function findName(int $idFeature, int $idLang): ?string
    {
        $name = \Db::getInstance()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'feature_lang` WHERE `id_feature` = ' . (int) $idFeature . ' AND `id_lang` = ' . (int) $idLang);

        return $name === false || $name === null || $name === '' ? null : (string) $name;
    }

    /** @return array<int,string> id_feature => name for the names equal to $name (collation-insensitive; callers compare exactly) */
    public function findNamesLike(string $name, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_feature`, `name` FROM `' . _DB_PREFIX_ . 'feature_lang` WHERE `id_lang` = ' . (int) $idLang . " AND `name` = '" . pSQL($name) . "'"
        );
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to look up features'));
        }

        return array_column($rows, 'name', 'id_feature');
    }
}
