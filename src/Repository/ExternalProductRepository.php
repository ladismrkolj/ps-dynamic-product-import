<?php

namespace ProductImport\Repository;

use ProductImport\Service\ImportedValues;

class ExternalProductRepository
{
    public function findLinkedProductIds(?int $idSource = null): array
    {
        $query = 'SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `date_deleted` IS NULL';
        if ($idSource !== null) {
            $query .= ' AND `id_source` = ' . (int) $idSource;
        }
        $rows = \Db::getInstance()->executeS($query . ' ORDER BY `id_product`');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to find linked products'));
        }
        return array_map('intval', array_column($rows, 'id_product'));
    }

    public function findProductId(int $idSource, string $externalId): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'"
        );

        return $id === false ? null : (int) $id;
    }

    /** @return array{id_product: int, imported_values: array<string, string>}|null */
    public function findLink(int $idSource, string $externalId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_product`, `imported_values` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'"
        );

        return $row ? ['id_product' => (int) $row['id_product'], 'imported_values' => ImportedValues::decode($row['imported_values'])] : null;
    }

    public function link(int $idSource, string $externalId, int $idProduct, ?int $idRun = null, ?array $importedValues = null): void
    {
        $values = $importedValues === null ? 'NULL' : "'" . pSQL(ImportedValues::encode($importedValues)) . "'";
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_external_product` (`id_source`, `external_id`, `id_product`, `id_run_last_seen`, `imported_values`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($externalId, true) . "', " . (int) $idProduct . ", " . ($idRun === null ? 'NULL' : (int) $idRun) . ', ' . $values . ", '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `id_product` = VALUES(`id_product`), `date_upd` = VALUES(`date_upd`), `date_missing` = NULL, `id_run_last_seen` = COALESCE(VALUES(`id_run_last_seen`), `id_run_last_seen`), `imported_values` = COALESCE(VALUES(`imported_values`), `imported_values`)"
        )) {
            throw new \RuntimeException(DbError::message('Unable to link external product'));
        }
    }

    public function saveImportedValues(int $idSource, string $externalId, array $importedValues): void
    {
        if (!\Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . "pi_external_product` SET `imported_values` = '" . pSQL(ImportedValues::encode($importedValues))
            . "' WHERE `id_source` = " . (int) $idSource . " AND `external_id` = '" . pSQL($externalId, true) . "'")) {
            throw new \RuntimeException(DbError::message('Unable to save imported values'));
        }
    }

    /** Forgets what the import last wrote and the feed hashes, so the next run overwrites manual edits. */
    public function resetImportedValues(int $idSource): void
    {
        if (!\Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'pi_external_product` SET `imported_values` = NULL, `feed_hash` = NULL WHERE `id_source` = ' . (int) $idSource)) {
            throw new \RuntimeException(DbError::message('Unable to reset imported values'));
        }
    }

    public function findFeedHash(int $idSource, string $externalId): ?string
    {
        $hash = \Db::getInstance()->getValue(
            'SELECT `feed_hash` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'"
        );

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /** Stores the hash of the feed item last imported completely; null forces the next run to import again. */
    public function setFeedHash(int $idSource, string $externalId, ?string $hash): void
    {
        if (!\Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'pi_external_product` SET `feed_hash` = ' . ($hash === null ? 'NULL' : "'" . pSQL($hash) . "'")
            . ' WHERE `id_source` = ' . (int) $idSource . " AND `external_id` = '" . pSQL($externalId, true) . "'")) {
            throw new \RuntimeException(DbError::message('Unable to save feed hash'));
        }
    }

    public function productExists(int $idProduct): bool
    {
        return (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . (int) $idProduct);
    }

    /** Marks an unchanged product link and the combination links of that product as seen in this run. */
    public function markSeen(int $idSource, string $externalId, int $idProduct, int $idRun): void
    {
        $db = \Db::getInstance();
        if (!$db->execute('UPDATE `' . _DB_PREFIX_ . 'pi_external_product` SET `id_run_last_seen` = ' . (int) $idRun . ', `date_missing` = NULL WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'")
            || !$db->execute('UPDATE `' . _DB_PREFIX_ . 'pi_external_combination` SET `id_run_last_seen` = ' . (int) $idRun . ' WHERE `id_source` = ' . (int) $idSource
            . ' AND `id_product` = ' . (int) $idProduct)) {
            throw new \RuntimeException(DbError::message('Unable to mark unchanged product as seen'));
        }
    }

    public function unlink(int $idSource, string $externalId): void
    {
        if (!\Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource . " AND `external_id` = '" . pSQL($externalId, true) . "'")) {
            throw new \RuntimeException(DbError::message('Unable to unlink external product'));
        }
    }

    /** Live (not tombstoned) links of the source that this run did not see. */
    public function findStaleForSource(int $idSource, int $currentRunId): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . ' AND `date_deleted` IS NULL AND (`id_run_last_seen` IS NULL OR `id_run_last_seen` <> ' . (int) $currentRunId . ')');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to find stale links'));
        }
        return $rows;
    }

    /** Starts the missing clock at $date; a link that is already missing keeps its first date. */
    public function markMissing(int $idSource, string $externalId, string $date): void
    {
        if (!\Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . "pi_external_product` SET `date_missing` = '" . pSQL($date) . "' WHERE `date_missing` IS NULL AND `id_source` = " . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'")) {
            throw new \RuntimeException(DbError::message('Unable to mark product as missing'));
        }
    }

    /** Whether any live (not tombstoned) link other than this exact (source, external id) row points to the product. */
    public function hasLinkFromOtherSource(int $idProduct, int $idSource, string $externalId): bool
    {
        return (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_product` = ' . (int) $idProduct
            . ' AND `date_deleted` IS NULL AND NOT (`id_source` = ' . (int) $idSource . " AND `external_id` = '" . pSQL($externalId, true) . "')");
    }

    /** @return list<string> external ids of the source whose product was deleted by someone else */
    public function findTombstonedExternalIds(int $idSource): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `external_id` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource . ' AND `date_deleted` IS NOT NULL');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to read deleted products'));
        }

        return array_map('strval', array_column($rows, 'external_id'));
    }

    /** @return list<array{external_id: string, deleted_name: ?string, date_deleted: string}> */
    public function listTombstones(int $idSource): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `external_id`, `deleted_name`, `date_deleted` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . ' AND `date_deleted` IS NOT NULL ORDER BY `date_deleted` DESC, `external_id`');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to read deleted products'));
        }

        return $rows;
    }

    /**
     * The product was deleted by someone else: keep its link rows as tombstones and drop what only made sense
     * while it existed (combination links, image memory). Returns the number of links tombstoned.
     */
    public function tombstoneProduct(int $idProduct, ?string $name = null): int
    {
        $db = \Db::getInstance();
        $nameSql = $name === null || trim($name) === '' ? 'NULL' : "'" . pSQL(\Tools::substr(trim($name), 0, 255)) . "'";
        if (!$db->execute('UPDATE `' . _DB_PREFIX_ . "pi_external_product` SET `date_deleted` = '" . pSQL(date('Y-m-d H:i:s')) . "', `deleted_name` = " . $nameSql . ', `date_missing` = NULL WHERE `id_product` = ' . (int) $idProduct . ' AND `date_deleted` IS NULL')) {
            throw new \RuntimeException(DbError::message('Unable to tombstone product links'));
        }
        $tombstoned = (int) $db->Affected_Rows();
        $this->forgetProductDetails($idProduct);

        return $tombstoned;
    }

    /** Deletes the combination links and image memory of a product that no longer exists. */
    public function forgetProductDetails(int $idProduct): void
    {
        $db = \Db::getInstance();
        if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_external_combination` WHERE `id_product` = ' . (int) $idProduct)
            || !$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_image_source` WHERE `id_product` = ' . (int) $idProduct)) {
            throw new \RuntimeException(DbError::message('Unable to remove product details'));
        }
    }

    /** Restore: forgets the tombstones so the next run creates the products fresh. @return int rows removed */
    public function restoreTombstones(int $idSource, ?string $externalId = null): int
    {
        $where = 'WHERE `id_source` = ' . (int) $idSource . ' AND `date_deleted` IS NOT NULL'
            . ($externalId === null ? '' : " AND `external_id` = '" . pSQL($externalId, true) . "'");
        if (!\Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_external_product` ' . $where)) {
            throw new \RuntimeException(DbError::message('Unable to restore deleted products'));
        }

        return (int) \Db::getInstance()->Affected_Rows();
    }
}
