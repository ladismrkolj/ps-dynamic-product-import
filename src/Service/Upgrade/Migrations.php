<?php

namespace ProductImport\Service\Upgrade;

/** Every migration step, in the order they run. Append new steps at the end; never reorder or remove. */
class Migrations
{
    /** @return list<MigrationStep> */
    public static function all(): array
    {
        return [
            new SourceLocationStep(),
            new SourceFormatStep(),
            new ObsoleteTabsStep(),
            new FeedDirStep(),
            new DeleteMissingDaysStep(),
        ];
    }
}
