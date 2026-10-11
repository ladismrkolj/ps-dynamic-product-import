<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\MissingLifecycle;
use ProductImport\Service\SchemaMigrator;

/**
 * 0.18.0 adds pi_source.delete_missing_days. Existing sources get the default: date_missing starts empty, so no
 * product can be deleted before that many days after the upgrade.
 */
class DeleteMissingDaysStep implements MigrationStep
{
    public function id(): string
    {
        return '0.18.0-delete-missing-days';
    }

    public function applies(array $actualColumns): bool
    {
        return in_array('delete_missing_days', $actualColumns['pi_source'] ?? [], true);
    }

    public function run(\Db $db, string $prefix): void
    {
        $sql = 'UPDATE ' . SchemaMigrator::quote($prefix . 'pi_source') . ' SET `delete_missing_days` = ' . self::defaultDays() . ' WHERE `delete_missing_days` IS NULL';
        if (!$db->execute($sql)) {
            throw new \RuntimeException(DbError::message('Statement failed `' . $sql . '`'));
        }
    }

    /** The value existing sources receive. */
    public static function defaultDays(): int
    {
        return MissingLifecycle::DEFAULT_DELETE_DAYS;
    }
}
