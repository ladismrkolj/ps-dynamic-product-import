<?php

/*
 * Not a version script (PrestaShop only runs upgrade-X.Y.Z.php). Every upgrade file requires this first.
 * During a Module Manager upload PrestaShop has already registered the OLD version's Composer autoloader,
 * which can be classmap-authoritative and so cannot find classes added in the new version, even though their
 * files are on disk. This prepends a PSR-4 loader for the new files. The upgrade path uses no third-party
 * packages, so vendor/autoload.php is not needed. A ProductImport class the old version already loaded in this
 * request cannot be redefined; the admin banner's "Update module data" button (a fresh request) is the fallback.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (!isset($GLOBALS['productimport_upgrade_autoload'])) {
    $GLOBALS['productimport_upgrade_autoload'] = true;
    $srcDir = dirname(__DIR__) . '/src/';
    spl_autoload_register(static function ($class) use ($srcDir) {
        if (strncmp($class, 'ProductImport\\', 14) !== 0) {
            return;
        }
        $file = $srcDir . str_replace('\\', '/', substr($class, 14)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }, true, true);
}
