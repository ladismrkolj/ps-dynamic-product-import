<?php

namespace ProductImport\Service\Upgrade;

use ProductImport\Service\SchemaMigrator;

/** 0.13 has no PIIMPORT_FEED_DIR setting. */
class FeedDirStep implements MigrationStep
{
    public function id(): string
    {
        return '0.16.0-feed-dir';
    }

    public function applies(array $actualColumns): bool
    {
        return !\Configuration::hasKey('PIIMPORT_FEED_DIR');
    }

    public function run(\Db $db, string $prefix): void
    {
        if (\Configuration::hasKey('PIIMPORT_FEED_DIR')) {
            return;
        }
        $dir = SchemaMigrator::defaultFeedDir();
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || !\Configuration::updateValue('PIIMPORT_FEED_DIR', $dir)) {
            throw new \RuntimeException('Could not create the import folder ' . $dir . ' or save its setting.');
        }
    }
}
