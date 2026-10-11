<?php

/*
 * PrestaShop runs every upgrade-X.Y.Z.php between the installed and the new module version, in order.
 * Each future release adds its own upgrade file calling the same Upgrader: it syncs the tables from
 * ProductImport\Service\SchemaCheck and runs the migration steps (Upgrade\Migrations) not yet applied,
 * so one call brings any older install to the current state and repeated calls change nothing.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_17_0($module)
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    return \ProductImport\Service\Upgrade\Upgrader::create()->run()['ok'];
}
