<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\SchemaCheck;

class SchemaCheckTest extends TestCase
{
    public function testCompleteSchemaReportsNothingAndIgnoresExtraColumns(): void
    {
        $actual = SchemaCheck::expectedColumns();
        $actual['pi_source'][] = 'legacy_column';

        $this->assertSame([], SchemaCheck::missing($actual));
    }

    public function testReportsMissingColumnPerTable(): void
    {
        $actual = SchemaCheck::expectedColumns();
        $actual['pi_source'] = array_values(array_diff($actual['pi_source'], ['location', 'xml_item_path']));

        $this->assertSame(['pi_source' => ['table_missing' => false, 'columns' => ['location', 'xml_item_path']]], SchemaCheck::missing($actual));
    }

    public function testReportsMissingTableWithAllItsColumns(): void
    {
        $actual = SchemaCheck::expectedColumns();
        unset($actual['pi_image_source']);

        $this->assertSame(['pi_image_source' => ['table_missing' => true, 'columns' => ['id_product', 'url_hash', 'id_image']]], SchemaCheck::missing($actual));
    }

    public function testCreateStatementsCoverEveryExpectedColumn(): void
    {
        $statements = SchemaCheck::createStatements('ps_', 'InnoDB');

        $this->assertSame(SchemaCheck::tables(), array_keys($statements));
        foreach (SchemaCheck::expectedColumns() as $table => $columns) {
            $this->assertStringContainsString('`ps_' . $table . '`', $statements[$table]);
            foreach ($columns as $column) {
                $this->assertStringContainsString('`' . $column . '` ', $statements[$table]);
            }
        }
        $this->assertStringContainsString('ENGINE=InnoDB', $statements['pi_source']);
    }
}
