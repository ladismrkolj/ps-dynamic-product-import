<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Repository\SchemaStatus;
use ProductImport\Service\SchemaMigrator;

/** 0.13 stored the source in json_url / json_file_path; it is now one `location`. */
class SourceLocationStep implements MigrationStep
{
    private const LEGACY = ['json_url', 'json_file_path'];

    public function id(): string
    {
        return '0.16.0-source-location';
    }

    public function applies(array $actualColumns): bool
    {
        return array_intersect(self::LEGACY, $actualColumns['pi_source'] ?? []) !== [];
    }

    public function run(\Db $db, string $prefix): void
    {
        $legacy = array_values(array_intersect(self::LEGACY, SchemaStatus::actualColumns()['pi_source'] ?? []));
        if ($legacy === []) {
            return;
        }
        $table = SchemaMigrator::quote($prefix . 'pi_source');
        $parts = [];
        foreach ($legacy as $column) {
            $parts[] = 'NULLIF(' . SchemaMigrator::quote($column) . ", '')";
        }
        $statements = ['UPDATE ' . $table . ' SET `location` = COALESCE(' . implode(', ', $parts) . ", '') WHERE `location` IS NULL OR `location` = ''"];
        foreach ($legacy as $column) {
            $statements[] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . SchemaMigrator::quote($column);
        }
        foreach ($statements as $sql) {
            if (!$db->execute($sql)) {
                throw new \RuntimeException(DbError::message('Statement failed `' . $sql . '`'));
            }
        }
    }
}
