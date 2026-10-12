<?php

/*
 * 0.20.0 adds per-source feature mapping: the table pi_feature_mapping and pi_source.unmapped_features are created by the
 * Upgrader's additive sync, then the 0.20.0-unmapped-features migration step gives sources that already map a `features`
 * field the setting 'create' (today's behaviour) and every other source 'ignore'. The 0.20.0-features-fingerprint step then converts
 * the stored features fingerprints of untouched products from names to ids.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_20_0($module)
{
    require_once __DIR__ . '/autoload.php';

    return \ProductImport\Service\Upgrade\Upgrader::create()->run()['ok'];
}
