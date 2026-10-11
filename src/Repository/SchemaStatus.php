<?php

namespace ProductImport\Repository;

use ProductImport\Service\SchemaCheck;

class SchemaStatus
{
    /** @var array<string,array{table_missing:bool,columns:list<string>}>|null */
    private static $missing;

    /** @var list<string>|null */
    private static $pending;

    /** @return array<string,list<string>>|null table name without prefix => existing column names; null when the lookup failed */
    public static function actualColumns(): ?array
    {
        $names = [];
        foreach (SchemaCheck::tables() as $table) {
            $names[] = "'" . pSQL(_DB_PREFIX_ . $table) . "'";
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `TABLE_NAME`, `COLUMN_NAME` FROM information_schema.COLUMNS WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (' . implode(', ', $names) . ') ORDER BY `TABLE_NAME`, `ORDINAL_POSITION`'
        );
        if ($rows === false) {
            return null;
        }
        $actual = [];
        $prefixLength = strlen(_DB_PREFIX_);
        foreach ($rows as $row) {
            $actual[substr((string) $row['TABLE_NAME'], $prefixLength)][] = (string) $row['COLUMN_NAME'];
        }

        return $actual;
    }

    /** Forgets the cached result, e.g. after a migration. */
    public static function reset(): void
    {
        self::$missing = null;
        self::$pending = null;
    }

    /** @return list<string> ids of registered migration steps that are not recorded as applied and still apply; cached per request */
    public static function pendingSteps(): array
    {
        if (self::$pending !== null) {
            return self::$pending;
        }
        $actual = self::actualColumns();
        if ($actual === null) {
            return self::$pending = [];
        }
        $applied = (new \ProductImport\Service\Upgrade\DbUpgradeStore())->applied();
        $pending = [];
        foreach (\ProductImport\Service\Upgrade\Migrations::all() as $step) {
            if (!in_array($step->id(), $applied, true) && $step->applies($actual)) {
                $pending[] = $step->id();
            }
        }

        return self::$pending = $pending;
    }

    /** @return array<string,array{table_missing:bool,columns:list<string>}> missing tables/columns keyed by table name without prefix; cached per request */
    public static function missing(): array
    {
        if (self::$missing !== null) {
            return self::$missing;
        }
        $actual = self::actualColumns();
        if ($actual === null) {
            // The check itself failed; do not claim the schema is broken.
            return self::$missing = [];
        }

        return self::$missing = SchemaCheck::missing($actual);
    }

    /**
     * HTML alert for the admin pages, or '' when the schema is complete.
     *
     * @param string $actionUrl URL (with token) the "Update tables" form posts to; '' hides the button
     */
    public static function alertHtml(string $actionUrl = ''): string
    {
        $missing = self::missing();
        $pending = self::pendingSteps();
        if ($missing === [] && $pending === []) {
            return '';
        }
        $items = '';
        foreach ($missing as $table => $info) {
            $items .= '<li><code>' . \Tools::safeOutput(_DB_PREFIX_ . $table) . '</code>: '
                . ($info['table_missing'] ? 'table is missing (' : 'missing columns ')
                . '<code>' . \Tools::safeOutput(implode(', ', $info['columns'])) . '</code>' . ($info['table_missing'] ? ')' : '') . '</li>';
        }
        if ($pending !== []) {
            $items .= '<li>data migration not run yet: <code>' . \Tools::safeOutput(implode(', ', $pending)) . '</code></li>';
        }
        $button = $actionUrl === '' ? '' : '<form method="post" action="' . \Tools::safeOutput($actionUrl) . '" onsubmit="return confirm(\'Back up the database first. Update the module data now?\');">'
            . '<button type="submit" name="submitPiMigrate" value="1" class="btn btn-default">Update module data</button></form>';

        return '<div class="alert alert-danger" role="alert"><h4>The module&#039;s database tables are from an older version</h4><ul>' . $items . '</ul>'
            . '<p>Upgrading the module normally updates its tables and data automatically. This page appears when that did not run. Back up the database, then update the module data here; sources, mappings and product links are kept.</p>' . $button . '</div>';
    }
}
