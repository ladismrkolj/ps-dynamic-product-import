<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\ImportedValues;
use ProductImport\Service\NativeFields;
use ProductImport\Service\SchemaMigrator;

/**
 * 0.20.0 fingerprints a product's features by ids instead of names, so renaming a feature or a value in the shop is not
 * read as a manual edit. A link whose stored features fingerprint still equals the current name-based one (the product
 * was not edited by hand) gets the id-based fingerprint. Any other link keeps its stored value, which cannot equal the
 * id-based one, so it stays protected as a manual edit.
 */
class FeaturesFingerprintStep implements MigrationStep
{
    public function id(): string
    {
        return '0.20.0-features-fingerprint';
    }

    public function applies(array $actualColumns): bool
    {
        return in_array('imported_values', $actualColumns['pi_external_product'] ?? [], true);
    }

    public function run(\Db $db, string $prefix): void
    {
        $links = $db->executeS(
            'SELECT e.`id_source`, e.`external_id`, e.`id_product`, e.`imported_values`, s.`id_lang_default` FROM ' . SchemaMigrator::quote($prefix . 'pi_external_product') . ' e'
            . ' INNER JOIN ' . SchemaMigrator::quote($prefix . 'pi_source') . ' s ON s.`id_source` = e.`id_source`'
            . " WHERE e.`imported_values` LIKE '%\"features\"%'"
        );
        if ($links === false) {
            throw new \RuntimeException(DbError::message('Could not read the product links'));
        }
        foreach ($links as $link) {
            $imported = ImportedValues::decode($link['imported_values']);
            if (!isset($imported['features'])) {
                continue;
            }
            $idLang = (int) $link['id_lang_default'];
            $legacy = $db->executeS(
                'SELECT fl.`name`, fvl.`value` FROM ' . SchemaMigrator::quote($prefix . 'feature_product') . ' fp'
                . ' INNER JOIN ' . SchemaMigrator::quote($prefix . 'feature_lang') . ' fl ON fl.`id_feature` = fp.`id_feature` AND fl.`id_lang` = ' . $idLang
                . ' INNER JOIN ' . SchemaMigrator::quote($prefix . 'feature_value_lang') . ' fvl ON fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . $idLang
                . ' WHERE fp.`id_product` = ' . (int) $link['id_product']
            );
            $ids = $db->executeS('SELECT `id_feature`, `id_feature_value` FROM ' . SchemaMigrator::quote($prefix . 'feature_product') . ' WHERE `id_product` = ' . (int) $link['id_product']);
            if ($legacy === false || $ids === false) {
                throw new \RuntimeException(DbError::message('Could not read the product features'));
            }
            $converted = self::convert($imported['features'], $legacy, $ids);
            if ($converted === null) {
                continue;
            }
            $imported['features'] = $converted;
            $sql = 'UPDATE ' . SchemaMigrator::quote($prefix . 'pi_external_product') . " SET `imported_values` = '" . pSQL(ImportedValues::encode($imported))
                . "' WHERE `id_source` = " . (int) $link['id_source'] . " AND `external_id` = '" . pSQL($link['external_id'], true) . "'";
            if (!$db->execute($sql)) {
                throw new \RuntimeException(DbError::message('Statement failed `' . $sql . '`'));
            }
        }
    }

    /**
     * @param string $stored the stored md5 of the features fingerprint
     * @param list<array{name: string, value: string}> $legacyRows the product's features by name in the source language
     * @param list<array{id_feature: int|string, id_feature_value: int|string}> $idRows the same features by id
     *
     * @return string|null the id-based fingerprint to store, or null to leave the link as it is (edited by hand)
     */
    public static function convert(string $stored, array $legacyRows, array $idRows): ?string
    {
        if ($stored !== ImportedValues::fingerprint(['features' => NativeFields::legacyFeaturesFingerprint($legacyRows)])['features']) {
            return null;
        }

        return ImportedValues::fingerprint(['features' => NativeFields::featuresFingerprint($idRows)])['features'];
    }
}
