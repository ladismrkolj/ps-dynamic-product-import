<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Repository\DbError;
use ProductImport\Service\SchemaCheck;

class DbUpgradeStore implements UpgradeStore
{
    public const CONFIG_KEY = 'PIIMPORT_MIGRATIONS';

    public function liveColumns(): ?array
    {
        $names = [];
        foreach (SchemaCheck::tables() as $table) {
            $names[] = "'" . pSQL(_DB_PREFIX_ . $table) . "'";
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `TABLE_NAME`, `COLUMN_NAME`, `IS_NULLABLE`, `COLUMN_TYPE` FROM information_schema.COLUMNS WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (' . implode(', ', $names) . ') ORDER BY `TABLE_NAME`, `ORDINAL_POSITION`'
        );
        if ($rows === false) {
            return null;
        }
        $live = [];
        $prefixLength = strlen(_DB_PREFIX_);
        foreach ($rows as $row) {
            $live[substr((string) $row['TABLE_NAME'], $prefixLength)][(string) $row['COLUMN_NAME']] = ['nullable' => $row['IS_NULLABLE'] === 'YES', 'type' => (string) $row['COLUMN_TYPE']];
        }

        return $live;
    }

    public function execute(string $sql): ?string
    {
        if (\Db::getInstance()->execute($sql)) {
            return null;
        }

        return DbError::message('Statement failed `' . (strlen($sql) > 200 ? substr($sql, 0, 200) . '…' : $sql) . '`');
    }

    public function applied(): array
    {
        $ids = json_decode((string) \Configuration::get(self::CONFIG_KEY), true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    public function saveApplied(array $ids): bool
    {
        return (bool) \Configuration::updateValue(self::CONFIG_KEY, json_encode(array_values($ids)));
    }

    public function db()
    {
        return \Db::getInstance();
    }

    public function prefix(): string
    {
        return _DB_PREFIX_;
    }

    public function log(string $message): void
    {
        \PrestaShopLogger::addLog('productimport: ' . $message, 3, null, 'productimport', 0, true);
    }
}
