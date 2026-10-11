<?php

/*
 * 0.19.0 connects resolved categories and brands to their mapping rows and removes the remembered category:
 * the 0.19.0-connect-auto-categories migration step converts remembered categories into mappings, then the Upgrader
 * drops pi_category_mapping.id_category_auto (no longer in SchemaCheck).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_19_0($module)
{
    require_once __DIR__ . '/autoload.php';

    return \ProductImport\Service\Upgrade\Upgrader::create()->run()['ok'];
}
