<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ReferenceCheck;

class ReferenceCheckTest extends TestCase
{
    public function testFreeReferenceIsKept(): void
    {
        $calls = [];
        [$values, $warning] = ReferenceCheck::apply(['name' => 'A', 'reference' => 'X-1'], 7, static function (string $reference, ?int $except) use (&$calls): ?int {
            $calls[] = [$reference, $except];
            return null;
        });
        self::assertSame(['name' => 'A', 'reference' => 'X-1'], $values);
        self::assertNull($warning);
        self::assertSame([['X-1', 7]], $calls);
    }

    public function testCollisionDropsReferenceOnly(): void
    {
        [$values, $warning] = ReferenceCheck::apply(['name' => 'A', 'reference' => 'X-1'], null, static fn (string $reference, ?int $except): ?int => 12);
        self::assertSame(['name' => 'A'], $values);
        self::assertSame('"X-1" is already used by product #12 — not written', $warning);
    }

    public function testEmptyOrMissingReferenceIsNotLookedUp(): void
    {
        $never = static function (): ?int {
            throw new \LogicException('lookup must not run');
        };
        foreach ([['name' => 'A'], ['reference' => null], ['reference' => '  '], ['reference' => ['x']]] as $values) {
            self::assertSame([$values, null], ReferenceCheck::apply($values, 1, $never));
        }
    }

    public function testFeedDuplicatesFirstItemWins(): void
    {
        self::assertSame([2 => 0, 4 => 0, 5 => 1], ReferenceCheck::feedDuplicates([0 => 'A', 1 => 'B', 2 => 'A', 3 => '', 4 => 'A', 5 => 'B', 6 => null, 7 => 'C']));
        self::assertSame([], ReferenceCheck::feedDuplicates([]));
    }
}
