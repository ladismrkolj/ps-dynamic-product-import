<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\MappingResolution;

class MappingResolutionTest extends TestCase
{
    public function testExistingMappingWinsOverDefaultAndNeverWrites(): void
    {
        foreach ([true, false] as $commit) {
            self::assertSame(['decision' => MappingResolution::OVERRIDE, 'missing' => false, 'clear' => false, 'connect' => false], MappingResolution::plan(5, true, 6, $commit));
        }
    }

    public function testDefaultAppliesToUnmappedValuesAndIsNotConnected(): void
    {
        self::assertSame(['decision' => MappingResolution::DEFAULT, 'missing' => false, 'clear' => false, 'connect' => false], MappingResolution::plan(null, false, 6, true));
    }

    public function testUnmappedValueWithoutDefaultIsConnectedOnCommitOnly(): void
    {
        self::assertSame(['decision' => MappingResolution::NAME_WALK, 'missing' => false, 'clear' => false, 'connect' => true], MappingResolution::plan(null, false, null, true));
        self::assertSame(['decision' => MappingResolution::NAME_WALK, 'missing' => false, 'clear' => false, 'connect' => false], MappingResolution::plan(null, false, null, false));
    }

    public function testMissingTargetIsClearedAndReconnectedOnCommit(): void
    {
        self::assertSame(['decision' => MappingResolution::NAME_WALK, 'missing' => true, 'clear' => false, 'connect' => true], MappingResolution::plan(7, false, null, true));
    }

    public function testMissingTargetInPreviewWritesNothingAndFallsBackToTheNameWalk(): void
    {
        self::assertSame(['decision' => MappingResolution::NAME_WALK, 'missing' => false, 'clear' => false, 'connect' => false], MappingResolution::plan(7, false, null, false));
    }

    public function testMissingTargetWithDefaultUsesTheDefaultAndClearsOnCommit(): void
    {
        self::assertSame(['decision' => MappingResolution::DEFAULT, 'missing' => true, 'clear' => true, 'connect' => false], MappingResolution::plan(7, false, 6, true));
        self::assertSame(['decision' => MappingResolution::DEFAULT, 'missing' => false, 'clear' => false, 'connect' => false], MappingResolution::plan(7, false, 6, false));
    }
}
