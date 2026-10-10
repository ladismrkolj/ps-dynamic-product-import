<?php

namespace ProductImport\Service;

class JsonFetcher
{
    private FeedDownloader $downloader;

    public function __construct(?FeedDownloader $downloader = null)
    {
        $this->downloader = $downloader ?? new FeedDownloader();
    }

    public function fetch(?string $url, ?string $filePath): array
    {
        // URL is the live source; the file is a fallback/manual-test path.
        if ($url !== null && trim($url) !== '') {
            $body = $this->fetchUrl($url);
        } elseif ($filePath !== null && trim($filePath) !== '') {
            $body = $this->downloader->readFile($filePath, 'JSON');
        } else {
            throw new \InvalidArgumentException('Provide a JSON URL or file path.');
        }

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

    private function fetchUrl(string $url): string
    {
        return $this->downloader->fetchUrl($url, 'JSON');
    }
}
