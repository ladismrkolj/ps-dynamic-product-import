<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\Upgrade\SourceMappings;

class SourceMappingsTest extends TestCase
{
    public function testRenamesATargetInFieldAndVariantMapping(): void
    {
        $rename = static function (array $mapping, string $column): array {
            if ($column === 'field_mapping' && array_key_exists('old_target', $mapping)) {
                $mapping['new_target'] = $mapping['old_target'];
                unset($mapping['old_target']);
            }
            if ($column === 'variant_mapping' && isset($mapping['fields']['old_target'])) {
                $mapping['fields']['new_target'] = $mapping['fields']['old_target'];
                unset($mapping['fields']['old_target']);
            }

            return $mapping;
        };

        $this->assertSame('{"name":"fields[\'n\']","new_target":"č/x"}', SourceMappings::transform('{"name":"fields[\'n\']","old_target":"č/x"}', 'field_mapping', $rename));
        $this->assertSame('{"fields":{"new_target":"v"}}', SourceMappings::transform('{"fields":{"old_target":"v"}}', 'variant_mapping', $rename));
    }

    public function testLeavesEmptyInvalidAndUnchangedValuesAlone(): void
    {
        $identity = static function (array $mapping): array {
            return $mapping;
        };

        $this->assertNull(SourceMappings::transform(null, 'variant_mapping', $identity));
        $this->assertSame('', SourceMappings::transform('', 'variant_mapping', $identity));
        $this->assertSame('not json', SourceMappings::transform('not json', 'field_mapping', $identity));
        $this->assertSame('{ "a": "b" }', SourceMappings::transform('{ "a": "b" }', 'field_mapping', $identity));
    }

    public function testEmptiedMappingStaysAnObject(): void
    {
        $this->assertSame('{}', SourceMappings::transform('{"a":"b"}', 'field_mapping', static function (): array {
            return [];
        }));
    }
}
