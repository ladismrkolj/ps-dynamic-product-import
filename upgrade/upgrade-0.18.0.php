<?php

/*
 * 0.18.0 adds the missing-product lifecycle: new columns (date_missing, date_deleted, deleted_name, delete_missing_days,
 * deleted_count; synced by the Upgrader from SchemaCheck), the 0.18.0-delete-missing-days migration step, and the
 * product and combination delete hooks.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_18_0($module)
{
    require_once __DIR__ . '/autoload.php';

    // Listed here, not read from the module class: an upload runs this file before the new class is loaded.
    if (!$module->registerHook(['actionProductDelete', 'actionObjectCombinationDeleteAfter'])) {
        return false;
    }

    return \ProductImport\Service\Upgrade\Upgrader::create()->run()['ok'];
}
