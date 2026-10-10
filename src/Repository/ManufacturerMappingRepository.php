<?php

namespace ProductImport\Repository;

class ManufacturerMappingRepository
{
    public function findAllForSource(int $idSource): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` WHERE `id_source` = ' . (int) $idSource . ' ORDER BY `id_mapping`');
        if ($rows === false) {
            throw new \RuntimeException('Unable to list manufacturer mappings.');
        }
        return $rows;
    }

    public function findOverride(int $idSource, string $name): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` WHERE `id_source` = ' . (int) $idSource
            . " AND `source_name` = '" . pSQL($name, true) . "'"
        );

        return $row ?: null;
    }

    public function upsertSeen(int $idSource, string $name): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` (`id_source`, `source_name`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($name, true) . "', '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException('Unable to record manufacturer name.');
        }
    }

    public function setOverride(int $idSource, string $name, ?int $idManufacturer): void
    {
        // Only previously discovered names can be edited.
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` SET `id_manufacturer` = '
            . ($idManufacturer === null ? 'NULL' : (int) $idManufacturer)
            . ", `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "' WHERE `id_source` = " . (int) $idSource
            . " AND `source_name` = '" . pSQL($name, true) . "'"
        )) {
            throw new \RuntimeException('Unable to save manufacturer override.');
        }
    }

    /** Insert or replace one mapping row with its override (used when importing a source configuration). */
    public function upsertWithOverride(int $idSource, string $name, ?int $idManufacturer): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` (`id_source`, `source_name`, `id_manufacturer`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($name, true) . "', " . ($idManufacturer === null ? 'NULL' : (int) $idManufacturer)
            . ", '" . pSQL(date('Y-m-d H:i:s')) . "') ON DUPLICATE KEY UPDATE `id_manufacturer` = VALUES(`id_manufacturer`), `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException('Unable to save imported brand mapping.');
        }
    }

    /** @return array<int,string> id_manufacturer => name for the names equal to $name (collation-insensitive; callers compare exactly) */
    public function findNamesLike(string $name): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_manufacturer`, `name` FROM `' . _DB_PREFIX_ . "manufacturer` WHERE `name` = '" . pSQL($name) . "'");
        if ($rows === false) {
            throw new \RuntimeException('Unable to look up manufacturers.');
        }

        return array_column($rows, 'name', 'id_manufacturer');
    }

    /** @return string|null manufacturer name by ID */
    public function findName(int $idManufacturer): ?string
    {
        $name = \Db::getInstance()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE `id_manufacturer` = ' . (int) $idManufacturer);

        return $name === false || $name === null || $name === '' ? null : (string) $name;
    }
}
