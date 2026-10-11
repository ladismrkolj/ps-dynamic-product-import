<?php

namespace ProductImport\Service;

/**
 * Plans the structural part of an upgrade from SchemaCheck's definition: missing tables and columns first,
 * and later (after data steps ran) the tightening of columns to their final definition.
 * Data changes are migration steps, see Upgrade\Upgrader.
 */
class SchemaMigrator
{
    /** @var string */
    private $prefix;

    /** @var string */
    private $engine;

    public function __construct(?string $prefix = null, ?string $engine = null)
    {
        $this->prefix = $prefix ?? _DB_PREFIX_;
        $this->engine = $engine ?? _MYSQL_ENGINE_;
    }

    /** Where install() and the feed-dir step put the import folder when none is configured. */
    public static function defaultFeedDir(): string
    {
        return _PS_ROOT_DIR_ . '/var/productimport/feeds';
    }

    public static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Additive sync. A NOT NULL column without a default is added as NULL; a migration step fills it and tighten() finishes it.
     *
     * @param array<string,list<string>> $actualColumns table name without prefix => existing column names (absent or empty = table missing)
     *
     * @return list<string> SQL statements in execution order; empty when nothing is missing
     */
    public function plan(array $actualColumns): array
    {
        $statements = [];
        $create = SchemaCheck::createStatements($this->prefix, $this->engine);
        foreach (SchemaCheck::columnDefinitions() as $table => $columns) {
            $existing = $actualColumns[$table] ?? [];
            if ($existing === []) {
                $statements[] = $create[$table];
                continue;
            }
            $previous = null;
            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    if (str_contains($definition, 'NOT NULL') && !str_contains($definition, 'DEFAULT') && !str_contains($definition, 'AUTO_INCREMENT')) {
                        $definition = str_replace('NOT NULL', 'NULL', $definition);
                    }
                    $statements[] = 'ALTER TABLE ' . self::quote($this->prefix . $table) . ' ADD COLUMN ' . self::quote($column) . ' ' . $definition
                        . ($previous === null ? ' FIRST' : ' AFTER ' . self::quote($previous));
                }
                $previous = $column;
            }
        }

        return $statements;
    }

    /**
     * MODIFY statements for columns that are nullable although the definition says NOT NULL, or whose VARCHAR/CHAR length differs.
     *
     * @param array<string,array<string,array{nullable:bool,type:string}>> $live table name without prefix => column => live nullability and type
     *
     * @return list<string>
     */
    public function tighten(array $live): array
    {
        $statements = [];
        foreach (SchemaCheck::columnDefinitions() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $info = $live[$table][$column] ?? null;
                if ($info === null) {
                    continue;
                }
                $differs = str_contains($definition, 'NOT NULL') && $info['nullable'];
                if (preg_match('/^(VARCHAR|CHAR)\((\d+)\)/', $definition, $match) === 1) {
                    $differs = $differs || strtolower($info['type']) !== strtolower($match[1] . '(' . $match[2] . ')');
                }
                if ($differs) {
                    $statements[] = 'ALTER TABLE ' . self::quote($this->prefix . $table) . ' MODIFY COLUMN ' . self::quote($column) . ' ' . $definition;
                }
            }
        }

        return $statements;
    }
}
