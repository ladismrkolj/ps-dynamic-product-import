<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\FeatureMapping;
use ProductImport\Service\FeatureValues;
use ProductImport\Service\NativeFields;
use ProductImport\Service\FeatureValueMatcher;

class FeatureMappingTest extends TestCase
{
    private static function row(string $action, ?int $idFeature = null, ?string $createName = null, ?string $expression = null): array
    {
        return ['action' => $action, 'id_feature' => $idFeature, 'create_name' => $createName, 'value_expression' => $expression];
    }

    /** A value formula stand-in: 'null' gives null, 'empty' blank text, 'upper' upper-cases, 'list' an array, anything else fails. */
    private static function transform(): callable
    {
        return static function (string $name, string $expression, string $value) {
            return match ($expression) {
                'null' => null,
                'empty' => '  ',
                'upper' => strtoupper($value),
                'list' => ['x'],
                default => throw new \RuntimeException('boom'),
            };
        };
    }

    private static function plan(array $pairs, array $rules, string $unmapped = 'ignore'): array
    {
        return FeatureMapping::plan($pairs, $rules, $unmapped, self::transform());
    }

    public function testUnmappedNamesFollowTheSourceSetting(): void
    {
        $ignored = self::plan(['Colour' => 'Red'], []);
        self::assertSame([], $ignored['entries']);
        self::assertSame(['Colour'], $ignored['unmapped']);
        self::assertSame([], $ignored['ignored']);

        $created = self::plan(['Colour' => 'Red'], [], 'create');
        self::assertSame([['name' => 'Colour', 'values' => ['Red'], 'id_feature' => null, 'create' => 'Colour', 'connect' => false]], $created['entries']);
        self::assertSame([], $created['unmapped']);

        $explicitNone = self::plan(['Colour' => 'Red'], ['Colour' => self::row('none')], 'create');
        self::assertSame($created['entries'], $explicitNone['entries']);
    }

    public function testIgnoreWinsOverTheSettingAndNeverRunsTheFormula(): void
    {
        $plan = self::plan(['Colour' => 'Red'], ['Colour' => self::row('ignore', null, null, 'boom')], 'create');
        self::assertSame([], $plan['entries']);
        self::assertSame(['Colour'], $plan['ignored']);
        self::assertSame([], $plan['errors']);
    }

    public function testMapUsesTheFeatureIdAndCreateIsConnectedAfterwards(): void
    {
        $plan = self::plan(['Finbox' => 'US-BOX', 'Weight' => '12'], ['Finbox' => self::row('create', null, 'Fin box'), 'Weight' => self::row('map', 6)]);
        self::assertSame([
            ['name' => 'Finbox', 'values' => ['US-BOX'], 'id_feature' => null, 'create' => 'Fin box', 'connect' => true],
            ['name' => 'Weight', 'values' => ['12'], 'id_feature' => 6, 'create' => null, 'connect' => false],
        ], $plan['entries']);
    }

    public function testValueFormulaRewritesSkipsAndReportsErrors(): void
    {
        $plan = self::plan(
            ['A' => 'x', 'B' => 'y', 'C' => 'z', 'D' => 'w', 'E' => 'v'],
            ['A' => self::row('create', null, 'A', 'upper'), 'B' => self::row('create', null, 'B', 'null'), 'C' => self::row('create', null, 'C', 'empty'),
                'D' => self::row('create', null, 'D', 'bad'), 'E' => self::row('create', null, 'E', 'list')]
        );
        self::assertSame(['X'], $plan['entries'][0]['values']);
        self::assertCount(1, $plan['entries']);
        self::assertSame(['B', 'C'], $plan['skipped']);
        self::assertSame(['D: boom', 'E: value must be text or a number'], $plan['errors']);
    }

    public function testFormulaAlsoAppliesToAnUnmappedNameThatTheSettingCreates(): void
    {
        $plan = self::plan(['A' => 'x'], ['A' => self::row('none', null, null, 'upper')], 'create');
        self::assertSame(['X'], $plan['entries'][0]['values']);
        $ignored = self::plan(['A' => 'x'], ['A' => self::row('none', null, null, 'upper')], 'ignore');
        self::assertSame([], $ignored['entries']);
    }

    public function testFirstFeedNameWinsWhenTwoMapToTheSameFeature(): void
    {
        $plan = self::plan(
            ['Finbox' => 'US-BOX', 'Fin Box' => 'US-Box', 'Fin box' => 'Slot', 'Weight' => '1', 'Mass' => '2'],
            ['Finbox' => self::row('create', null, 'Fin box'), 'Fin Box' => self::row('create', null, 'Fin box'), 'Fin box' => self::row('create', null, 'Fin box'),
                'Weight' => self::row('map', 6), 'Mass' => self::row('map', 6)]
        );
        self::assertSame(['Finbox', 'Weight'], array_column($plan['entries'], 'name'));
        self::assertSame([
            'feed names "Finbox" and "Fin Box" both map to the feature "Fin box"; "Finbox" is used',
            'feed names "Finbox" and "Fin box" both map to the feature "Fin box"; "Finbox" is used',
            'feed names "Weight" and "Mass" both map to the feature #6; "Weight" is used',
        ], $plan['conflicts']);
    }

    public function testAFormulaThatSkipsTheFirstNameLetsTheNextOneIn(): void
    {
        $plan = self::plan(
            ['Weight (kg)' => 'TBA', 'Mass' => '3'],
            ['Weight (kg)' => self::row('map', 6, null, 'null'), 'Mass' => self::row('map', 6)]
        );
        self::assertSame(['Mass'], array_column($plan['entries'], 'name'));
        self::assertSame([], $plan['conflicts']);
    }

    public function testRowsThatCannotActCountAsNotMapped(): void
    {
        foreach ([self::row('map'), self::row('create'), self::row('create', null, '  '), self::row('weird', 3, 'X')] as $row) {
            self::assertSame('none', FeatureMapping::effective($row)['action']);
        }
        self::assertSame('none', FeatureMapping::effective(null)['action']);
        self::assertSame(['action' => 'map', 'id_feature' => 3, 'create_name' => null, 'value_expression' => 'x'], FeatureMapping::effective(['action' => 'map', 'id_feature' => '3', 'create_name' => '', 'value_expression' => ' x ']));
    }

    public function testValueMatchingIgnoresCaseAndWhitespace(): void
    {
        self::assertSame('us-box', FeatureValueMatcher::normalize("  US-Box \u{00A0}"));
        self::assertSame('a b c', FeatureValueMatcher::normalize("A\t B\n\nC"));
        self::assertSame('črna škoda', FeatureValueMatcher::normalize('ČRNA  Škoda'));
        $index = FeatureValueMatcher::index([9 => 'US-BOX', 4 => 'us-box', 7 => 'Slot']);
        self::assertSame(['us-box' => 4, 'slot' => 7], $index, 'the lowest id wins');
        self::assertSame(4, $index[FeatureValueMatcher::normalize('US-Box')] ?? null);
        self::assertArrayNotHasKey(FeatureValueMatcher::normalize('US BOX'), $index);
    }

    public function testMultipleValuesAreFormulaedPerValueAndDeduplicated(): void
    {
        $plan = self::plan(['Area' => ['7', '9', '9 ']], ['Area' => self::row('create', null, 'Area', 'upper')]);
        self::assertSame(['7', '9'], $plan['entries'][0]['values']);
        $none = self::plan(['Area' => ['7', '9']], ['Area' => self::row('create', null, 'Area', 'null')]);
        self::assertSame(['Area'], $none['skipped']);
        $plain = self::plan(['Area' => ['7', '9']], ['Area' => self::row('map', 6)]);
        self::assertSame(['7', '9'], $plain['entries'][0]['values']);
    }

    public function testFeatureValuesAggregateProductFirstThenVariantsDistinctly(): void
    {
        $combined = FeatureValues::combine([
            ['Area' => '7', 'Colour' => 'Red'],
            ['Area' => '9', 'Fin' => 'US-Box'],
            ['Area' => ' 9 ', 'Fin' => 'us-box', 'Colour' => 'RED'],
            ['Area' => '7', 'Area2' => '1'],
            [],
        ]);
        self::assertSame(['Area' => ['7', '9'], 'Colour' => ['Red'], 'Fin' => ['US-Box'], 'Area2' => ['1']], $combined);
        self::assertSame(['Area', 'Colour', 'Fin', 'Area2'], array_keys($combined));
        self::assertSame([], FeatureValues::combine([]));
    }

    public function testFingerprintCoversSeveralValuesOfOneFeatureInAnyOrder(): void
    {
        $a = NativeFields::featuresFingerprint([['id_feature' => 4, 'id_feature_value' => 9], ['id_feature' => 4, 'id_feature_value' => 7]]);
        $b = NativeFields::featuresFingerprint([['id_feature' => '4', 'id_feature_value' => '7'], ['id_feature' => '4', 'id_feature_value' => '9']]);
        self::assertSame($a, $b);
        self::assertNotSame($a, NativeFields::featuresFingerprint([['id_feature' => 4, 'id_feature_value' => 7]]));
    }
}
