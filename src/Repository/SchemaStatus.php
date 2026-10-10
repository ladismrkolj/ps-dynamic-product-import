<?php

namespace ProductImport\Repository;

use ProductImport\Service\SchemaCheck;

class SchemaStatus
{
    /** @var array<string,array{table_missing:bool,columns:list<string>}>|null */
    private static $missing;

    /** @return array<string,array{table_missing:bool,columns:list<string>}> missing tables/columns keyed by table name without prefix; cached per request */
    public static function missing(): array
    {
        if (self::$missing !== null) {
            return self::$missing;
        }
        $names = [];
        foreach (SchemaCheck::tables() as $table) {
            $names[] = "'" . pSQL(_DB_PREFIX_ . $table) . "'";
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `TABLE_NAME`, `COLUMN_NAME` FROM information_schema.COLUMNS WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (' . implode(', ', $names) . ')'
        );
        if ($rows === false) {
            // The check itself failed; do not claim the schema is broken.
            return self::$missing = [];
        }
        $actual = [];
        $prefixLength = strlen(_DB_PREFIX_);
        foreach ($rows as $row) {
            $actual[substr((string) $row['TABLE_NAME'], $prefixLength)][] = (string) $row['COLUMN_NAME'];
        }

        return self::$missing = SchemaCheck::missing($actual);
    }

    /** HTML alert for the admin pages, or '' when the schema is complete. */
    public static function alertHtml(): string
    {
        $missing = self::missing();
        if ($missing === []) {
            return '';
        }
        $items = '';
        foreach ($missing as $table => $info) {
            $items .= '<li><code>' . \Tools::safeOutput(_DB_PREFIX_ . $table) . '</code>: '
                . ($info['table_missing'] ? 'table is missing (' : 'missing columns ')
                . '<code>' . \Tools::safeOutput(implode(', ', $info['columns'])) . '</code>' . ($info['table_missing'] ? ')' : '') . '</li>';
        }

        return '<div class="alert alert-danger" role="alert"><h4>The module&#039;s database tables are from an older version</h4><ul>' . $items . '</ul>'
            . '<p>Export your sources as YAML if you want to keep them, then uninstall and reinstall the module (this deletes sources, mappings and product links), and re-import the YAML.</p></div>';
    }
}
