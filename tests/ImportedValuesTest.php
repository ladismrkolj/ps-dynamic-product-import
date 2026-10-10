<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ImportedValues;

class ImportedValuesTest extends TestCase
{
    public function testFieldIsWritableUnlessStoreDiffersFromLastImport(): void
    {
        $imported = ImportedValues::fingerprint(['name.1' => 'Board', 'price' => ImportedValues::number(10)]);
        $current = ImportedValues::fingerprint(['name.1' => 'Board', 'price' => ImportedValues::number(12), 'reference' => 'X']);

        self::assertTrue(ImportedValues::writable($imported, $current, 'name.1'), 'unchanged since last import');
        self::assertFalse(ImportedValues::writable($imported, $current, 'price'), 'edited by hand');
        self::assertTrue(ImportedValues::writable($imported, $current, 'reference'), 'never written by the import');
        self::assertTrue(ImportedValues::writable([], $current, 'price'), 'reset snapshot overwrites');
    }

    public function testNumbersFromDatabaseAndFeedCompareEqual(): void
    {
        self::assertSame(ImportedValues::number('12.500000'), ImportedValues::number(12.5));
        self::assertSame(ImportedValues::number('-0.000000'), ImportedValues::number(0));
    }

    public function testEditorRewrappingIsNotAManualEdit(): void
    {
        self::assertSame(ImportedValues::text("Fin &amp; leash\n v2"), ImportedValues::text('<p>Fin &amp; <b>leash</b> v2</p>'));
        self::assertNotSame(ImportedValues::text('<p>Fin v2</p>'), ImportedValues::text('<p>Fin v3</p>'));
    }

    public function testDecodeIgnoresInvalidSnapshots(): void
    {
        $values = ['price' => md5('1')];
        self::assertSame($values, ImportedValues::decode(ImportedValues::encode($values)));
        self::assertSame([], ImportedValues::decode(null));
        self::assertSame([], ImportedValues::decode('not json'));
        self::assertSame([], ImportedValues::decode('[1]'));
    }
}
