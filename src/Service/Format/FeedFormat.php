<?php

namespace ProductImport\Service\Format;

interface FeedFormat
{
    /** Stable identifier stored in pi_source.source_format. */
    public function name(): string;

    public function label(): string;

    /**
     * Parses a downloaded feed body into a list of items.
     *
     * @param array $source source row; format options are read from it by field name
     * @return list<array>
     * @throws \InvalidArgumentException when a required option is missing or invalid
     * @throws \RuntimeException when the body cannot be parsed
     */
    public function parse(string $body, array $source): array;

    /**
     * Extra source fields this format needs, keyed by source column name.
     *
     * @return array<string, array{label: string, required: bool}>
     */
    public function options(): array;
}
