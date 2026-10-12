<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluator;
use ProductImport\Service\FeatureMapper;
use ProductImport\Service\NativeFields;

require_once __DIR__ . '/FeatureFakes.php';

class FeatureMapperTest extends TestCase
{
    private function mapper(FakeFeatureMappings $mappings, ?FakeFeatureResolver $resolver = null): FeatureMapper
    {
        return new FeatureMapper($mappings, $resolver ?? new FakeFeatureResolver(), new ExpressionEvaluator());
    }

    public function testCommitRecordsNewNamesOncePerRunAndConnectsCreatedFeatures(): void
    {
        $mappings = new FakeFeatureMappings();
        $mappings->rows['Finbox'] = FakeFeatureMappings::row('Finbox', 'create', null, 'Fin box');
        $resolver = new FakeFeatureResolver();
        $mapper = $this->mapper($mappings, $resolver);
        $first = $mapper->resolve(['Finbox' => 'US-BOX', 'Colour' => 'Red'], [], 1, 'ignore', 2, true);
        $second = $mapper->resolve(['Finbox' => 'US-Box', 'Colour' => 'Blue'], [], 1, 'ignore', 2, true);
        self::assertSame([['id_feature' => 5, 'id_feature_value' => 1001]], $first['rows']);
        self::assertSame(['Colour'], $first['unmapped']);
        self::assertSame(['seen:Finbox', 'seen:Colour', 'connect:Finbox:5'], $mappings->writes, 'every name is recorded once per run, the created feature is connected once');
        self::assertSame('map', $mappings->rows['Finbox']['action']);
        self::assertSame([['id_feature' => 5, 'id_feature_value' => 1002]], $second['rows']);
        self::assertSame([['Fin box', 'US-BOX', 2], [5, 'US-Box', 2]], $resolver->calls, 'after connecting, the feature is addressed by id');
    }

    public function testPreviewNeverWritesAndNamesTheTargetFeatures(): void
    {
        $mappings = new FakeFeatureMappings();
        $mappings->rows['Weight (kg)'] = FakeFeatureMappings::row('Weight (kg)', 'map', 6);
        $resolver = new FakeFeatureResolver();
        $result = $this->mapper($mappings, $resolver)->resolve(['Weight (kg)' => '12', 'Finbox' => 'US'], [], 1, 'create', 1, false);
        self::assertSame([['Weight', '12'], ['Finbox', 'US']], $result['pairs']);
        self::assertSame([], $result['rows']);
        self::assertSame([], $mappings->writes);
        self::assertSame([], $resolver->calls);
    }

    public function testFormulaSeesTheValueAndTheFeedItem(): void
    {
        $mappings = new FakeFeatureMappings();
        $mappings->rows['Size'] = FakeFeatureMappings::row('Size', 'create', null, 'Size', "value ~ ' ' ~ fields['unit']");
        $result = $this->mapper($mappings)->resolve(['Size' => '12'], ['unit' => 'm2'], 1, 'ignore', 1, false);
        self::assertSame([['Size', '12 m2']], $result['pairs']);
    }

    public function testRejectsAFeaturesValueThatIsNotAMapOrList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper(new FakeFeatureMappings())->resolve('text', [], 1, 'ignore', 1, true);
    }

    public function testDiscoveryCountsProductsAndKeepsFirstDistinctSamples(): void
    {
        $mappings = new FakeFeatureMappings();
        $items = [];
        foreach (range(1, 8) as $n) {
            $items[] = ['specs' => ['Finbox' => 'V' . $n, 'Colour' => 'Red']];
        }
        $items[] = ['specs' => ['Colour' => 'Red', 'Weight' => '']];
        $items[] = ['specs' => null];
        $items[] = 'broken';
        $result = $this->mapper($mappings)->discover($items, 1, "fields['specs']");
        self::assertSame(['unique_features' => 2, 'items_scanned' => 11, 'failed_items' => 1, 'errors' => ['Item 10: Item is not an object.'], 'errors_omitted' => 0], $result);
        self::assertSame(8, $mappings->discovered['Finbox']['count']);
        self::assertSame(['V1', 'V2', 'V3', 'V4', 'V5'], $mappings->discovered['Finbox']['samples']);
        self::assertSame(['count' => 9, 'samples' => ['Red']], $mappings->discovered['Colour']);
        self::assertArrayNotHasKey('Weight', $mappings->discovered);
        self::assertSame(NativeFields::FEATURE_NAME_LIMIT, 128);
    }
}
