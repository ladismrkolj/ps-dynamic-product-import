<?php

namespace ProductImport\Service\Upgrade;

/**
 * One data or structure change that SchemaCheck's additive sync cannot express (data moves, renames,
 * rewrites of saved JSON, settings). Runs once per install; its id is then recorded by the Upgrader.
 */
interface MigrationStep
{
    /** Stable id: "<version that introduced it>-<name>", e.g. "0.16.0-source-location". Never reuse or rename. */
    public function id(): string;

    /**
     * Cheap guard on the live columns (table name without prefix => column names), evaluated after the additive sync.
     * When false the step is skipped and still recorded as applied.
     *
     * @param array<string,list<string>> $actualColumns
     */
    public function applies(array $actualColumns): bool;

    /**
     * Must be safe to run when its trigger is absent. Throws on failure, with the database message when there is one.
     *
     * @param \Db $db
     */
    public function run(\Db $db, string $prefix): void;
}
