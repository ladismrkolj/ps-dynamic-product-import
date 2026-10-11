<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Service\SchemaMigrator;

/**
 * The single entry point for upgrades (every upgrade-X.Y.Z.php and the admin button):
 * (a) additive schema sync, (b) pending migration steps in order, (c) tightening columns to their final definition.
 */
class Upgrader
{
    /** @var SchemaMigrator */
    private $schema;

    /** @var list<MigrationStep> */
    private $steps;

    /** @var UpgradeStore */
    private $store;

    /** @param list<MigrationStep> $steps */
    public function __construct(SchemaMigrator $schema, array $steps, UpgradeStore $store)
    {
        $this->schema = $schema;
        $this->steps = $steps;
        $this->store = $store;
    }

    public static function create(): self
    {
        return new self(new SchemaMigrator(), Migrations::all(), new DbUpgradeStore());
    }

    /**
     * @return array{ok:bool,statements:list<string>,steps:list<string>,error:?string} statements run, step ids run now, first error
     */
    public function run(): array
    {
        $report = ['ok' => false, 'statements' => [], 'steps' => [], 'error' => null];
        $live = $this->store->liveColumns();
        if ($live === null) {
            return $this->fail($report, 'Could not read the database structure.');
        }
        foreach ($this->schema->plan($this->names($live)) as $sql) {
            if (($error = $this->store->execute($sql)) !== null) {
                return $this->fail($report, $error);
            }
            $report['statements'][] = $sql;
        }
        $applied = $this->store->applied();
        foreach ($this->steps as $step) {
            if (in_array($step->id(), $applied, true)) {
                continue;
            }
            try {
                $columns = $this->store->liveColumns();
                if ($columns === null) {
                    throw new \RuntimeException('Could not read the database structure.');
                }
                if ($step->applies($this->names($columns))) {
                    $step->run($this->store->db(), $this->store->prefix());
                }
            } catch (\Throwable $error) {
                return $this->fail($report, 'Migration step ' . $step->id() . ' failed: ' . $error->getMessage());
            }
            $applied[] = $step->id();
            if (!$this->store->saveApplied($applied)) {
                return $this->fail($report, 'Migration step ' . $step->id() . ' ran but could not be recorded.');
            }
            $report['steps'][] = $step->id();
        }
        $live = $this->store->liveColumns();
        if ($live === null) {
            return $this->fail($report, 'Could not read the database structure.');
        }
        foreach ($this->schema->tighten($live) as $sql) {
            if (($error = $this->store->execute($sql)) !== null) {
                return $this->fail($report, $error);
            }
            $report['statements'][] = $sql;
        }
        $report['ok'] = true;

        return $report;
    }

    /** For a fresh install: its tables are already current, so no existing step needs to run. */
    public function markAllApplied(): bool
    {
        return $this->store->saveApplied(array_values(array_unique(array_merge($this->store->applied(), array_map(static function (MigrationStep $step): string {
            return $step->id();
        }, $this->steps)))));
    }

    /**
     * @param array<string,array<string,mixed>> $live
     *
     * @return array<string,list<string>>
     */
    private function names(array $live): array
    {
        return array_map(static function (array $columns): array {
            return array_keys($columns);
        }, $live);
    }

    /**
     * @param array{ok:bool,statements:list<string>,steps:list<string>,error:?string} $report
     *
     * @return array{ok:bool,statements:list<string>,steps:list<string>,error:?string}
     */
    private function fail(array $report, string $message): array
    {
        $this->store->log($message);
        $report['error'] = $message;

        return $report;
    }
}
