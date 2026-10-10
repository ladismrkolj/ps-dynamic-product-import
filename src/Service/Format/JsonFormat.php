<?php

namespace ProductImport\Service\Format;

class JsonFormat implements FeedFormat
{
    public function name(): string
    {
        return 'json';
    }

    public function label(): string
    {
        return 'JSON';
    }

    public function options(): array
    {
        return [];
    }

    public function parse(string $body, array $source): array
    {
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        // Associative decoding alone cannot distinguish a JSON object from a list.
        if (!is_array($decoded) || substr(ltrim($body), 0, 1) !== '[') {
            throw new \RuntimeException('JSON must contain a top-level array of products.');
        }

        return $decoded;
    }
}
