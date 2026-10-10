<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_14_0($module)
{
    foreach (['pi_external_product', 'pi_external_combination'] as $table) {
        $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . $table) . "' AND COLUMN_NAME = 'imported_values'");
        if ($rows === false || ($rows === [] && !Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD COLUMN `imported_values` TEXT NULL AFTER `id_run_last_seen`'))) {
            return false;
        }
    }
    return true;
}
