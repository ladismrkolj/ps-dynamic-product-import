<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\SchemaMigrator;

/**
 * 0.19.0 removes the remembered category (pi_category_mapping.id_category_auto): a resolved category is now written
 * into the ordinary mapping (id_category). Remembered values of rows that have no mapping, whose category still
 * exists and whose source has no default category become that mapping; rows of sources with a default were
 * ignored at runtime and are not converted. The step then drops the column; a failure leaves the step unrecorded.
 */
class ConnectAutoCategoriesStep implements MigrationStep
{
    public function id(): string
    {
        return '0.19.0-connect-auto-categories';
    }

    public function applies(array $actualColumns): bool
    {
        return in_array('id_category_auto', $actualColumns['pi_category_mapping'] ?? [], true);
    }

    public function run(\Db $db, string $prefix): void
    {
        $mapping = SchemaMigrator::quote($prefix . 'pi_category_mapping');
        $rows = $db->executeS(
            'SELECT m.`id_mapping`, m.`id_category`, m.`id_category_auto`, s.`default_id_category`, c.`id_category` AS `existing`'
            . ' FROM ' . $mapping . ' m'
            . ' INNER JOIN ' . SchemaMigrator::quote($prefix . 'pi_source') . ' s ON s.`id_source` = m.`id_source`'
            . ' LEFT JOIN ' . SchemaMigrator::quote($prefix . 'category') . ' c ON c.`id_category` = m.`id_category_auto`'
            . ' WHERE m.`id_category` IS NULL AND m.`id_category_auto` IS NOT NULL'
        );
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Could not read the category mappings'));
        }
        foreach ($rows as $row) {
            if (!self::shouldConvert(
                $row['id_category'] === null ? null : (int) $row['id_category'],
                $row['id_category_auto'] === null ? null : (int) $row['id_category_auto'],
                $row['default_id_category'] === null ? null : (int) $row['default_id_category'],
                $row['existing'] !== null
            )) {
                continue;
            }
            self::execute($db, 'UPDATE ' . $mapping . ' SET `id_category` = ' . (int) $row['id_category_auto'] . ' WHERE `id_mapping` = ' . (int) $row['id_mapping']);
        }
        self::execute($db, 'ALTER TABLE ' . $mapping . ' DROP COLUMN `id_category_auto`');
    }

    private static function execute(\Db $db, string $sql): void
    {
        if (!$db->execute($sql)) {
            throw new \RuntimeException(DbError::message('Statement failed `' . $sql . '`'));
        }
    }

    public static function shouldConvert(?int $mapped, ?int $remembered, ?int $sourceDefault, bool $rememberedExists): bool
    {
        return $mapped === null && $remembered !== null && $rememberedExists && $sourceDefault === null;
    }
}
