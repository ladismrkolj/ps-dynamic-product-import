<?php

namespace ProductImport\Service;

/**
 * The module's database schema: the single definition install() builds its tables from
 * and the live database is checked against.
 */
class SchemaCheck
{
    private const UTF8_BIN = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';

    /** Table (without prefix) => column => SQL definition, plus index definitions. */
    private const TABLES = [
        'pi_source' => [
            'columns' => [
                'id_source' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                'name' => 'VARCHAR(191) NOT NULL',
                'technical_key' => 'VARCHAR(64) NOT NULL',
                'location' => 'TEXT NOT NULL',
                'source_format' => "VARCHAR(16) NOT NULL DEFAULT 'json'",
                'xml_item_path' => 'VARCHAR(255) NULL',
                'identifier_field' => 'VARCHAR(191) NOT NULL',
                'filter_expression' => 'LONGTEXT NULL',
                'field_mapping' => 'LONGTEXT NOT NULL',
                'variant_mapping' => 'LONGTEXT NULL',
                'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'root_category_id' => 'INT UNSIGNED NULL',
                'id_supplier' => 'INT UNSIGNED NULL',
                'default_id_category' => 'INT UNSIGNED NULL',
                'default_id_manufacturer' => 'INT UNSIGNED NULL',
                'id_lang_default' => 'INT UNSIGNED NOT NULL DEFAULT 1',
                'deactivate_missing' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'consecutive_empty_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'date_add' => 'DATETIME NOT NULL',
                'date_upd' => 'DATETIME NOT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_source`)', 'UNIQUE KEY `technical_key` (`technical_key`)'],
        ],
        'pi_manufacturer_mapping' => [
            'columns' => [
                'id_mapping' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                'id_source' => 'INT UNSIGNED NOT NULL',
                'source_name' => 'VARCHAR(191) ' . self::UTF8_BIN . ' NOT NULL',
                'id_manufacturer' => 'INT UNSIGNED NULL',
                'date_seen' => 'DATETIME NULL',
                'date_upd' => 'DATETIME NOT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_mapping`)', 'UNIQUE KEY `source_name` (`id_source`, `source_name`)'],
        ],
        'pi_category_mapping' => [
            'columns' => [
                'id_mapping' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                'id_source' => 'INT UNSIGNED NOT NULL',
                'source_path_hash' => 'CHAR(40) NOT NULL',
                'source_path' => 'TEXT NOT NULL',
                'id_category' => 'INT UNSIGNED NULL',
                'id_category_auto' => 'INT UNSIGNED NULL',
                'date_seen' => 'DATETIME NULL',
                'date_upd' => 'DATETIME NOT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_mapping`)', 'UNIQUE KEY `source_path` (`id_source`, `source_path_hash`)'],
        ],
        'pi_external_product' => [
            'columns' => [
                'id_source' => 'INT UNSIGNED NOT NULL',
                'external_id' => 'VARCHAR(191) ' . self::UTF8_BIN . ' NOT NULL',
                'id_product' => 'INT UNSIGNED NOT NULL',
                'id_run_last_seen' => 'INT UNSIGNED NULL',
                'imported_values' => 'TEXT NULL',
                'feed_hash' => 'CHAR(32) NULL',
                'date_upd' => 'DATETIME NOT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_source`, `external_id`)'],
        ],
        'pi_external_combination' => [
            'columns' => [
                'id_source' => 'INT UNSIGNED NOT NULL',
                'external_id' => 'VARCHAR(191) ' . self::UTF8_BIN . ' NOT NULL',
                'id_product_attribute' => 'INT UNSIGNED NOT NULL',
                'id_product' => 'INT UNSIGNED NOT NULL',
                'id_run_last_seen' => 'INT UNSIGNED NULL',
                'imported_values' => 'TEXT NULL',
                'date_upd' => 'DATETIME NOT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_source`, `external_id`)'],
        ],
        'pi_import_run' => [
            'columns' => [
                'id_run' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                'id_source' => 'INT UNSIGNED NOT NULL',
                'started_at' => 'DATETIME NOT NULL',
                'finished_at' => 'DATETIME NULL',
                'status' => 'VARCHAR(32) NOT NULL',
                'triggered_by' => 'VARCHAR(32) NULL',
                'created_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'updated_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'skipped_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'failed_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'unchanged_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'error_log' => 'LONGTEXT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_run`)', 'KEY `source_run` (`id_source`, `id_run`)'],
        ],
        // Remembers which feed image URL became which product image, so known URLs are never downloaded again.
        'pi_image_source' => [
            'columns' => [
                'id_product' => 'INT UNSIGNED NOT NULL',
                'url_hash' => 'CHAR(40) NOT NULL',
                'id_image' => 'INT UNSIGNED NOT NULL',
            ],
            'keys' => ['PRIMARY KEY (`id_product`, `url_hash`)', 'KEY `id_image` (`id_image`)'],
        ],
    ];

    /** @return array<string,list<string>> table name without prefix => expected column names */
    public static function expectedColumns(): array
    {
        $expected = [];
        foreach (self::TABLES as $table => $definition) {
            $expected[$table] = array_keys($definition['columns']);
        }

        return $expected;
    }

    /** @return array<string,array<string,string>> table name without prefix => column => SQL definition, in table order */
    public static function columnDefinitions(): array
    {
        $definitions = [];
        foreach (self::TABLES as $table => $definition) {
            $definitions[$table] = $definition['columns'];
        }

        return $definitions;
    }

    /** @return list<string> table names without prefix, in creation order */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /** @return array<string,string> table name without prefix => CREATE TABLE IF NOT EXISTS statement */
    public static function createStatements(string $prefix, string $engine): array
    {
        $statements = [];
        foreach (self::TABLES as $table => $definition) {
            $lines = [];
            foreach ($definition['columns'] as $column => $sql) {
                $lines[] = '`' . $column . '` ' . $sql;
            }
            $statements[$table] = 'CREATE TABLE IF NOT EXISTS `' . $prefix . $table . '` (' . implode(', ', array_merge($lines, $definition['keys']))
                . ') ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4';
        }

        return $statements;
    }

    /**
     * @param array<string,list<string>> $actual table name without prefix => existing column names (absent or empty = table missing)
     *
     * @return array<string,array{table_missing:bool,columns:list<string>}> only tables with something missing; extra columns are ignored
     */
    public static function missing(array $actual): array
    {
        $missing = [];
        foreach (self::expectedColumns() as $table => $columns) {
            $existing = $actual[$table] ?? [];
            $lacking = array_values(array_diff($columns, $existing));
            if ($lacking !== []) {
                $missing[$table] = ['table_missing' => $existing === [], 'columns' => $lacking];
            }
        }

        return $missing;
    }
}
