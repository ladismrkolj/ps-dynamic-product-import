<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_15_0($module)
{
    $db = Db::getInstance();
    $columns = [
        ['pi_external_product', 'feed_hash', 'CHAR(32) NULL AFTER `imported_values`'],
        ['pi_import_run', 'unchanged_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `failed_count`'],
    ];
    foreach ($columns as [$table, $column, $definition]) {
        $rows = $db->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . $table) . "' AND COLUMN_NAME = '" . pSQL($column) . "'");
        if ($rows === false || ($rows === [] && !$db->execute('ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD COLUMN `' . $column . '` ' . $definition))) {
            return false;
        }
    }
    return (bool) $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_image_source` (
        `id_product` INT UNSIGNED NOT NULL,
        `url_hash` CHAR(40) NOT NULL,
        `id_image` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`id_product`, `url_hash`), KEY `id_image` (`id_image`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
}
