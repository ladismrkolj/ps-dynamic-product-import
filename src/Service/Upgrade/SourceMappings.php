<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\SchemaMigrator;

/** Lets a migration step rewrite the saved field_mapping and variant_mapping JSON of every source. */
class SourceMappings
{
    public const COLUMNS = ['field_mapping', 'variant_mapping'];

    /**
     * @param callable(array,string):array $fn receives the decoded mapping and its column name, returns the new mapping
     */
    public static function rewrite(\Db $db, string $prefix, callable $fn): void
    {
        $table = SchemaMigrator::quote($prefix . 'pi_source');
        $rows = $db->executeS('SELECT `id_source`, `field_mapping`, `variant_mapping` FROM ' . $table);
        if ($rows === false) {
            throw new \RuntimeException(DbError::message('Could not read the sources'));
        }
        foreach ($rows as $row) {
            $set = [];
            foreach (self::COLUMNS as $column) {
                $new = self::transform($row[$column] === null ? null : (string) $row[$column], $column, $fn);
                if ($new !== $row[$column]) {
                    $set[] = SchemaMigrator::quote($column) . ' = ' . ($new === null ? 'NULL' : "'" . pSQL($new, true) . "'");
                }
            }
            if ($set !== [] && !$db->execute('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE `id_source` = ' . (int) $row['id_source'])) {
                throw new \RuntimeException(DbError::message('Could not update source ' . (int) $row['id_source']));
            }
        }
    }

    /**
     * @param callable(array,string):array $fn
     *
     * @return string|null the JSON to store; the input unchanged when it is empty, not a JSON object, or the callback changed nothing
     */
    public static function transform(?string $json, string $column, callable $fn): ?string
    {
        if ($json === null || trim($json) === '') {
            return $json;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        $result = $fn($decoded, $column);
        if ($result === $decoded) {
            return $json;
        }
        $encoded = json_encode($result === [] ? (object) [] : $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
