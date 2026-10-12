<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ValueCatalog;

class ValueCatalogTest extends TestCase
{
    public function testGroupsCaseAndWhitespaceVariantsLeadByCount(): void
    {
        $groups = ValueCatalog::group([
            'US-BOX' => ['count' => 5, 'p' => 5, 'v' => 0],
            'US-Box' => ['count' => 12, 'p' => 10, 'v' => 4],
            ' us-box ' => ['count' => 1, 'p' => 0, 'v' => 1],
            'Slot' => ['count' => 3, 'p' => 3, 'v' => 0],
        ]);
        self::assertSame('US-Box', $groups[0]['value']);
        self::assertSame(18, $groups[0]['count']);
        self::assertSame([15, 5], [$groups[0]['p'], $groups[0]['v']]);
        self::assertSame([['value' => 'US-BOX', 'count' => 5], ['value' => ' us-box ', 'count' => 1]], $groups[0]['also']);
        self::assertSame('Slot', $groups[1]['value']);
    }

    public function testResultValueSkippedErrorAndExistingMatch(): void
    {
        $formula = static function (string $v) {
            return match ($v) {
                'TBA' => null, 'bad' => throw new \RuntimeException('boom'), default => strtoupper($v)
            };
        };
        $existing = ['us-box' => true];
        self::assertSame(['kind' => 'value', 'text' => 'US-BOX', 'match' => true], ValueCatalog::result('us-box', $formula, $existing));
        self::assertSame('skipped', ValueCatalog::result('TBA', $formula)['kind']);
        self::assertSame(['kind' => 'error', 'text' => 'boom', 'match' => false], ValueCatalog::result('bad', $formula));
        self::assertSame('skipped', ValueCatalog::result('  ', null)['kind']);
    }

    public function testScanFieldCountsEmptyErrorsAndFilter(): void
    {
        $items = [['q' => '5'], ['q' => '5'], ['q' => 'x'], ['q' => null], ['q' => '7', 'hide' => true], 'junk'];
        $catalog = new ValueCatalog();
        $all = $catalog->scanField($items, "num(fields['q']) > 6 ? 'big' : (fields['q'] === null ? null : (fields['q'] == 'x' ? 1 / 0 : 'small'))", null, null);
        self::assertSame(['small' => 2, 'big' => 1], array_column($all['values'], 'count', 'value'));
        self::assertSame(1, $all['empty']);
        self::assertSame([['message' => $all['errors'][0]['message'], 'count' => 1]], $all['errors']);
        $filtered = $catalog->scanField($items, "fields['q']", null, "fields['hide'] !== true");
        self::assertSame(['5' => 2, 'x' => 1], array_column($filtered['values'], 'count', 'value'));
        self::assertFalse($filtered['truncated']);
    }

    public function testScanFeatureSeparatesProductAndVariantOrigins(): void
    {
        $items = [
            ['spec' => ['Fin' => 'US-Box'], 'variants' => [['f' => 'US-BOX'], ['f' => 'US-BOX']]],
            ['spec' => ['Fin' => 'US-Box', 'Other' => 'x'], 'variants' => []],
        ];
        $result = (new ValueCatalog())->scanFeature($items, 'Fin', "fields['spec']", "fields['variants']", "{'Fin': variant['f']}", null);
        self::assertSame(1, $result['distinct_total']);
        $row = $result['values'][0];
        self::assertSame(['US-Box', 3, 2, 1], [$row['value'], $row['count'], $row['p'], $row['v']]);
        self::assertSame([['value' => 'US-BOX', 'count' => 1]], $row['also']);
    }
}
