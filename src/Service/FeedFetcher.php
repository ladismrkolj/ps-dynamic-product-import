<?php

namespace ProductImport\Service;

use ProductImport\Service\Format\FeedFormats;

class FeedFetcher
{
    /** Downloads or reads the source location and parses it with the selected format. */
    public static function fetch(array $source, ?FeedDownloader $downloader = null, ?string $feedDir = null): array
    {
        $format = FeedFormats::get((string) ($source['source_format'] ?? 'json'));
        $location = FeedLocation::resolve((string) ($source['location'] ?? ''), $feedDir);
        $downloader ??= new FeedDownloader();
        $body = $location['type'] === 'url'
            ? $downloader->fetchUrl($location['value'], $format->label())
            : $downloader->readFile($location['value'], $format->label());

        return $format->parse($body, $source);
    }
}
