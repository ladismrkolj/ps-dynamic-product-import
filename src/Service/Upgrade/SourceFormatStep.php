<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\SchemaMigrator;

/** 0.13 allowed a NULL or empty source_format; the column is now NOT NULL DEFAULT 'json'. */
class SourceFormatStep implements MigrationStep
{
    public function id(): string
    {
        return '0.16.0-source-format';
    }

    public function applies(array $actualColumns): bool
    {
        return in_array('source_format', $actualColumns['pi_source'] ?? [], true);
    }

    public function run(\Db $db, string $prefix): void
    {
        $sql = 'UPDATE ' . SchemaMigrator::quote($prefix . 'pi_source') . " SET `source_format` = 'json' WHERE `source_format` IS NULL OR `source_format` = ''";
        if (!$db->execute($sql)) {
            throw new \RuntimeException(DbError::message('Statement failed `' . $sql . '`'));
        }
    }
}
