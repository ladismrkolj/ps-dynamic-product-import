<?php

namespace ProductImport\Repository;

use ProductImport\Service\ImportedValues;

class ExternalCombinationRepository
{
    /** @return array{id_product_attribute: int, imported_values: array<string, string>}|null */
    public function findLink(int $idSource, string $externalId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_product_attribute`, `imported_values` FROM `' . _DB_PREFIX_ . 'pi_external_combination` WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'"
        );

        return $row ? ['id_product_attribute' => (int) $row['id_product_attribute'], 'imported_values' => ImportedValues::decode($row['imported_values'])] : null;
    }

    public function link(int $idSource, string $externalId, int $idProductAttribute, int $idProduct, ?int $idRun = null, ?array $importedValues = null): void
    {
        $values = $importedValues === null ? 'NULL' : "'" . pSQL(ImportedValues::encode($importedValues)) . "'";
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_external_combination` (`id_source`, `external_id`, `id_product_attribute`, `id_product`, `id_run_last_seen`, `imported_values`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($externalId, true) . "', " . (int) $idProductAttribute . ", " . (int) $idProduct . ", " . ($idRun === null ? 'NULL' : (int) $idRun) . ', ' . $values . ", '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `id_product_attribute` = VALUES(`id_product_attribute`), `id_product` = VALUES(`id_product`), `date_upd` = VALUES(`date_upd`), `id_run_last_seen` = COALESCE(VALUES(`id_run_last_seen`), `id_run_last_seen`), `imported_values` = COALESCE(VALUES(`imported_values`), `imported_values`)"
        )) {
            throw new \RuntimeException(DbError::message('Unable to link external combination'));
        }
    }

    /** Forgets what the import last wrote, so the next run overwrites manual edits. */
    public function resetImportedValues(int $idSource): void
    {
        if (!\Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'pi_external_combination` SET `imported_values` = NULL WHERE `id_source` = ' . (int) $idSource)) {
            throw new \RuntimeException(DbError::message('Unable to reset imported values'));
        }
    }

    /** A combination deleted by hand: forget its link (no tombstone), so the next run recreates it if the feed still has it. */
    public function unlinkCombination(int $idProductAttribute): void
    {
        if (!\Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_external_combination` WHERE `id_product_attribute` = ' . (int) $idProductAttribute)) {
            throw new \RuntimeException(DbError::message('Unable to unlink external combination'));
        }
    }

    public function findStaleForSource(int $idSource, int $currentRunId): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_external_combination` WHERE `id_source` = ' . (int) $idSource
            . ' AND (`id_run_last_seen` IS NULL OR `id_run_last_seen` <> ' . (int) $currentRunId . ')');
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Unable to find stale links'));
        }
        return $rows;
    }
}
