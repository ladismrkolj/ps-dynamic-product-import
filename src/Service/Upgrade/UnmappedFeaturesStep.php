<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\SchemaMigrator;

/**
 * 0.20.0 adds pi_source.unmapped_features (what happens to feed feature names without a mapping). The column default is
 * 'ignore'; a source that already maps a `features` field keeps today's behaviour (every feed name becomes a feature of
 * the same name), so it gets 'create'.
 */
class UnmappedFeaturesStep implements MigrationStep
{
    public function id(): string
    {
        return '0.20.0-unmapped-features';
    }

    public function applies(array $actualColumns): bool
    {
        return in_array('unmapped_features', $actualColumns['pi_source'] ?? [], true);
    }

    public function run(\Db $db, string $prefix): void
    {
        $table = SchemaMigrator::quote($prefix . 'pi_source');
        $rows = $db->executeS('SELECT `id_source`, `field_mapping` FROM ' . $table);
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Could not read the sources'));
        }
        $create = [];
        foreach ($rows as $row) {
            if (self::settingFor((string) $row['field_mapping']) === 'create') {
                $create[] = (int) $row['id_source'];
            }
        }
        if ($create === []) {
            return;
        }
        $sql = 'UPDATE ' . $table . " SET `unmapped_features` = 'create' WHERE `id_source` IN (" . implode(', ', $create) . ')';
        if (!$db->execute($sql)) {
            throw new \RuntimeException(DbError::message('Statement failed `' . $sql . '`'));
        }
    }

    /** @param string $fieldMapping the saved JSON object of target => expression */
    public static function settingFor(string $fieldMapping): string
    {
        $mapping = json_decode($fieldMapping, true);

        return is_array($mapping) && array_key_exists('features', $mapping) ? 'create' : 'ignore';
    }
}
