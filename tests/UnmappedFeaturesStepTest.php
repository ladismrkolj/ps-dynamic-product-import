<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ImportedValues;
use ProductImport\Service\NativeFields;
use ProductImport\Service\SchemaCheck;
use ProductImport\Service\Upgrade\FeaturesFingerprintStep;
use ProductImport\Service\Upgrade\Migrations;
use ProductImport\Service\Upgrade\UnmappedFeaturesStep;

class UnmappedFeaturesStepTest extends TestCase
{
    public function testSourcesWithAFeaturesMappingKeepTodaysBehaviour(): void
    {
        self::assertSame('create', UnmappedFeaturesStep::settingFor('{"name":"fields[\'title\']","features":"fields[\'attrs\']"}'));
        self::assertSame('ignore', UnmappedFeaturesStep::settingFor('{"name":"fields[\'title\']"}'));
        self::assertSame('ignore', UnmappedFeaturesStep::settingFor('{}'));
        self::assertSame('ignore', UnmappedFeaturesStep::settingFor('not json'));
        self::assertSame('ignore', UnmappedFeaturesStep::settingFor('[]'));
    }

    public function testAppliesOnlyWhenTheColumnExistsAndRunsLast(): void
    {
        $step = new UnmappedFeaturesStep();
        self::assertTrue($step->applies(['pi_source' => ['id_source', 'unmapped_features']]));
        self::assertFalse($step->applies(['pi_source' => ['id_source']]));
        $ids = array_map(static fn ($migration): string => $migration->id(), Migrations::all());
        self::assertSame(['0.20.0-unmapped-features', '0.20.0-features-fingerprint'], array_slice($ids, -2));
    }

    public function testNewSourcesDefaultToIgnoreAndTheTableIsInTheSchema(): void
    {
        $columns = SchemaCheck::columnDefinitions();
        self::assertSame("VARCHAR(16) NOT NULL DEFAULT 'ignore'", $columns['pi_source']['unmapped_features']);
        self::assertSame(
            ['id_mapping', 'id_source', 'source_name', 'action', 'id_feature', 'create_name', 'value_expression', 'product_count', 'sample_values', 'date_seen', 'date_upd'],
            array_keys($columns['pi_feature_mapping'])
        );
        self::assertStringContainsString('utf8mb4_bin', $columns['pi_feature_mapping']['source_name']);
        self::assertStringContainsString('UNIQUE KEY `source_name` (`id_source`, `source_name`)', SchemaCheck::createStatements('ps_', 'InnoDB')['pi_feature_mapping']);
    }

    public function testFingerprintConversionOnlyForUntouchedProducts(): void
    {
        $legacy = [['name' => 'Fin box', 'value' => 'US-Box'], ['name' => 'Weight', 'value' => '1']];
        $ids = [['id_feature' => 13, 'id_feature_value' => 40], ['id_feature' => 12, 'id_feature_value' => 41]];
        $stored = ImportedValues::fingerprint(['features' => NativeFields::legacyFeaturesFingerprint($legacy)])['features'];
        $idBased = ImportedValues::fingerprint(['features' => NativeFields::featuresFingerprint($ids)])['features'];
        self::assertSame($idBased, FeaturesFingerprintStep::convert($stored, $legacy, $ids));
        self::assertNull(FeaturesFingerprintStep::convert($stored, [['name' => 'Fin box', 'value' => 'Slot']], $ids), 'edited by hand: left as it is');
        self::assertNotSame($stored, $idBased);
        self::assertSame($idBased, ImportedValues::fingerprint(['features' => NativeFields::featuresFingerprint(array_reverse($ids))])['features'], 'order does not matter');
        self::assertTrue((new FeaturesFingerprintStep())->applies(['pi_external_product' => ['imported_values']]));
    }
}
