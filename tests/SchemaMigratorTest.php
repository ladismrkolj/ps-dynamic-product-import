<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\SchemaCheck;
use ProductImport\Service\SchemaMigrator;

class SchemaMigratorTest extends TestCase
{
    private function migrator(string $prefix = 'ps_'): SchemaMigrator
    {
        return new SchemaMigrator($prefix, 'InnoDB');
    }

    /** @return array<string,array<string,array{nullable:bool,type:string}>> */
    private function liveCurrent(): array
    {
        $live = [];
        foreach (SchemaCheck::columnDefinitions() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $type = preg_match('/^(VARCHAR|CHAR)\(\d+\)/', $definition, $match) === 1 ? strtolower($match[0]) : 'text';
                $live[$table][$column] = ['nullable' => !str_contains($definition, 'NOT NULL'), 'type' => $type];
            }
        }

        return $live;
    }

    public function testCurrentSchemaPlansNothing(): void
    {
        $this->assertSame([], $this->migrator()->plan(SchemaCheck::expectedColumns()));
        $this->assertSame([], $this->migrator()->tighten($this->liveCurrent()));
    }

    public function testExtraColumnsAreNeverDropped(): void
    {
        $actual = SchemaCheck::expectedColumns();
        $actual['pi_source'][] = 'json_url';

        $this->assertSame([], $this->migrator()->plan($actual));
    }

    public function testVersion016PlansExactlyOneAddColumn(): void
    {
        $actual = SchemaCheck::expectedColumns();
        $actual['pi_category_mapping'] = array_values(array_diff($actual['pi_category_mapping'], ['date_seen']));

        $this->assertSame(
            ['ALTER TABLE `ps_pi_category_mapping` ADD COLUMN `date_seen` DATETIME NULL AFTER `id_category`'],
            $this->migrator()->plan($actual)
        );
    }

    public function testVersion013PlansAdditiveSyncOnly(): void
    {
        $actual = SchemaCheck::expectedColumns();
        $actual['pi_source'] = array_values(array_diff($actual['pi_source'], ['location']));
        array_splice($actual['pi_source'], 3, 0, ['json_url', 'json_file_path']);
        $actual['pi_manufacturer_mapping'] = array_values(array_diff($actual['pi_manufacturer_mapping'], ['date_seen']));
        $actual['pi_category_mapping'] = array_values(array_diff($actual['pi_category_mapping'], ['date_seen']));
        $actual['pi_external_product'] = array_values(array_diff($actual['pi_external_product'], ['imported_values', 'feed_hash']));
        $actual['pi_external_combination'] = array_values(array_diff($actual['pi_external_combination'], ['imported_values']));
        $actual['pi_import_run'] = array_values(array_diff($actual['pi_import_run'], ['unchanged_count']));
        unset($actual['pi_image_source']);

        $create = SchemaCheck::createStatements('ps_', 'InnoDB');
        $this->assertSame([
            'ALTER TABLE `ps_pi_source` ADD COLUMN `location` TEXT NULL AFTER `technical_key`',
            'ALTER TABLE `ps_pi_manufacturer_mapping` ADD COLUMN `date_seen` DATETIME NULL AFTER `id_manufacturer`',
            'ALTER TABLE `ps_pi_category_mapping` ADD COLUMN `date_seen` DATETIME NULL AFTER `id_category`',
            'ALTER TABLE `ps_pi_external_product` ADD COLUMN `imported_values` TEXT NULL AFTER `id_run_last_seen`',
            'ALTER TABLE `ps_pi_external_product` ADD COLUMN `feed_hash` CHAR(32) NULL AFTER `imported_values`',
            'ALTER TABLE `ps_pi_external_combination` ADD COLUMN `imported_values` TEXT NULL AFTER `id_run_last_seen`',
            'ALTER TABLE `ps_pi_import_run` ADD COLUMN `unchanged_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `failed_count`',
            $create['pi_image_source'],
        ], $this->migrator()->plan($actual));
    }

    public function testTightenModifiesNullableAndShortColumns(): void
    {
        $live = $this->liveCurrent();
        $live['pi_source']['location']['nullable'] = true;
        $live['pi_source']['source_format'] = ['nullable' => true, 'type' => 'varchar(8)'];

        $this->assertSame([
            'ALTER TABLE `ps_pi_source` MODIFY COLUMN `location` TEXT NOT NULL',
            "ALTER TABLE `ps_pi_source` MODIFY COLUMN `source_format` VARCHAR(16) NOT NULL DEFAULT 'json'",
        ], $this->migrator()->tighten($live));
    }

    public function testMissingTableIsCreated(): void
    {
        $actual = SchemaCheck::expectedColumns();
        unset($actual['pi_import_run']);

        $plan = $this->migrator()->plan($actual);

        $this->assertCount(1, $plan);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_pi_import_run` (', $plan[0]);
    }

    public function testPrefixStartingWithDigitIsQuoted(): void
    {
        $actual = SchemaCheck::expectedColumns();
        $actual['pi_category_mapping'] = array_values(array_diff($actual['pi_category_mapping'], ['date_seen']));
        unset($actual['pi_image_source']);

        $plan = $this->migrator('2evul_')->plan($actual);

        $this->assertSame('ALTER TABLE `2evul_pi_category_mapping` ADD COLUMN `date_seen` DATETIME NULL AFTER `id_category`', $plan[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `2evul_pi_image_source` (', $plan[1]);
    }
}
