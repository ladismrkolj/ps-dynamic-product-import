<?php

namespace ProductImport\Service;

use ProductImport\Service\Format\FeedFormats;

class FeedFetcher
{
    /** Downloads or reads the source location and parses it with the selected format. */
    public static function fetch(array $source, ?FeedDownloader $downloader = null, ?string $feedDir = null): array
    {
        return FeedFormats::get((string) ($source['source_format'] ?? 'json'))->parse(self::fetchBody($source, $downloader, $feedDir), $source);
    }

    /** Resolves the source location and returns the raw body, with the same retry, size cap and path rules as fetch(). */
    public static function fetchBody(array $source, ?FeedDownloader $downloader = null, ?string $feedDir = null): string
    {
        $format = FeedFormats::get((string) ($source['source_format'] ?? 'json'));
        $location = FeedLocation::resolve((string) ($source['location'] ?? ''), $feedDir);
        $downloader ??= new FeedDownloader();

        return $location['type'] === 'url'
            ? $downloader->fetchUrl($location['value'], $format->label())
            : $downloader->readFile($location['value'], $format->label());
    }
}
