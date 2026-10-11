<?php

namespace ProductImport\Service\Upgrade;

/** What the Upgrader needs from the outside world: the database, the applied-step list and logging. */
interface UpgradeStore
{
    /** @return array<string,array<string,array{nullable:bool,type:string}>>|null table without prefix => column => live info; null when the lookup failed */
    public function liveColumns(): ?array;

    /** @return string|null the database error message, or null on success */
    public function execute(string $sql): ?string;

    /** @return list<string> */
    public function applied(): array;

    /** @param list<string> $ids */
    public function saveApplied(array $ids): bool;

    /** The \Db handed to steps. */
    public function db();

    public function prefix(): string;

    public function log(string $message): void;
}
