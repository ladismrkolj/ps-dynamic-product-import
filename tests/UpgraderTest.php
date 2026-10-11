<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\SchemaCheck;
use ProductImport\Service\SchemaMigrator;
use ProductImport\Service\Upgrade\MigrationStep;
use ProductImport\Service\Upgrade\Upgrader;
use ProductImport\Service\Upgrade\UpgradeStore;

class UpgraderTest extends TestCase
{
    public function testRunsSyncThenStepsThenTighteningAndRecordsSteps(): void
    {
        $store = new FakeUpgradeStore(true);
        $upgrader = new Upgrader(new SchemaMigrator('ps_', 'InnoDB'), [new FakeStep('a', $store), new FakeStep('b', $store)], $store);

        $report = $upgrader->run();

        $this->assertTrue($report['ok']);
        $this->assertSame(['sql:ADD location', 'step:a', 'step:b', 'sql:MODIFY location'], $store->events);
        $this->assertSame(['a', 'b'], $store->applied);
        $this->assertSame(['a', 'b'], $report['steps']);
    }

    public function testStepsRunOnlyOnce(): void
    {
        $store = new FakeUpgradeStore(false);
        $upgrader = new Upgrader(new SchemaMigrator('ps_', 'InnoDB'), [new FakeStep('a', $store)], $store);

        $upgrader->run();
        $second = $upgrader->run();

        $this->assertSame(['step:a'], $store->events);
        $this->assertSame([], $second['steps']);
    }

    public function testFailureStopsWithoutRecordingTheFailedStep(): void
    {
        $store = new FakeUpgradeStore(false);
        $steps = [new FakeStep('a', $store), new FakeStep('b', $store, true), new FakeStep('c', $store)];
        $upgrader = new Upgrader(new SchemaMigrator('ps_', 'InnoDB'), $steps, $store);

        $report = $upgrader->run();

        $this->assertFalse($report['ok']);
        $this->assertSame('Migration step b failed: boom', $report['error']);
        $this->assertSame(['a'], $store->applied);
        $this->assertSame(['step:a', 'step:b'], $store->events);
        $this->assertSame(['Migration step b failed: boom'], $store->logged);
    }

    public function testSkippedStepIsStillRecorded(): void
    {
        $store = new FakeUpgradeStore(false);
        $upgrader = new Upgrader(new SchemaMigrator('ps_', 'InnoDB'), [new FakeStep('a', $store, false, false)], $store);

        $upgrader->run();

        $this->assertSame([], $store->events);
        $this->assertSame(['a'], $store->applied);
    }

    public function testFreshInstallMarksEveryStepApplied(): void
    {
        $store = new FakeUpgradeStore(false);
        $upgrader = new Upgrader(new SchemaMigrator('ps_', 'InnoDB'), [new FakeStep('a', $store), new FakeStep('b', $store)], $store);

        $this->assertTrue($upgrader->markAllApplied());
        $report = $upgrader->run();

        $this->assertSame(['a', 'b'], $store->applied);
        $this->assertSame([], $store->events);
        $this->assertTrue($report['ok']);
    }
}

class FakeStep implements MigrationStep
{
    public function __construct(private string $id, private FakeUpgradeStore $store, private bool $fails = false, private bool $applies = true)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function applies(array $actualColumns): bool
    {
        return $this->applies;
    }

    public function run($db, string $prefix): void
    {
        $this->store->events[] = 'step:' . $this->id;
        if ($this->fails) {
            throw new RuntimeException('boom');
        }
    }
}

class FakeUpgradeStore implements UpgradeStore
{
    /** @var list<string> */
    public $events = [];
    /** @var list<string> */
    public $applied = [];
    /** @var list<string> */
    public $logged = [];

    /** @param bool $outdated pi_source lacks `location` (added as NULL, tightened afterwards) */
    public function __construct(private bool $outdated)
    {
    }

    public function liveColumns(): ?array
    {
        $live = [];
        foreach (SchemaCheck::columnDefinitions() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if ($this->outdated && $column === 'location') {
                    continue;
                }
                $live[$table][$column] = ['nullable' => !str_contains($definition, 'NOT NULL'), 'type' => preg_match('/^(VARCHAR|CHAR)\(\d+\)/', $definition, $m) === 1 ? strtolower($m[0]) : 'text'];
            }
        }
        if ($this->outdated && $this->events !== []) {
            $live['pi_source']['location'] = ['nullable' => true, 'type' => 'text'];
        }

        return $live;
    }

    public function execute(string $sql): ?string
    {
        $this->events[] = 'sql:' . (str_contains($sql, 'ADD COLUMN `location`') ? 'ADD location' : (str_contains($sql, 'MODIFY COLUMN `location`') ? 'MODIFY location' : $sql));

        return null;
    }

    public function applied(): array
    {
        return $this->applied;
    }

    public function saveApplied(array $ids): bool
    {
        $this->applied = $ids;

        return true;
    }

    public function db()
    {
        return null;
    }

    public function prefix(): string
    {
        return 'ps_';
    }

    public function log(string $message): void
    {
        $this->logged[] = $message;
    }
}
