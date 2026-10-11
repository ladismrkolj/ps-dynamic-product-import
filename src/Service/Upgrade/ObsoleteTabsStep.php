<?php

namespace ProductImport\Service\Upgrade;

/** 0.13 registered hidden admin tabs whose controllers no longer exist. */
class ObsoleteTabsStep implements MigrationStep
{
    private const CLASSES = ['AdminPiCategoryMap', 'AdminPiManufacturerMap'];

    public function id(): string
    {
        return '0.16.0-obsolete-tabs';
    }

    public function applies(array $actualColumns): bool
    {
        return true;
    }

    public function run(\Db $db, string $prefix): void
    {
        foreach (self::CLASSES as $class) {
            $idTab = (int) \Tab::getIdFromClassName($class);
            if ($idTab > 0 && !(new \Tab($idTab))->delete()) {
                throw new \RuntimeException('Could not delete the admin tab ' . $class . '.');
            }
        }
    }
}
