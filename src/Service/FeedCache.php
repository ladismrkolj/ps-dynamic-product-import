<?php

namespace ProductImport\Service;

use ProductImport\Service\Format\FeedFormats;
use ProductImport\Service\Format\XmlFormat;

/**
 * Disk cache of decoded feed items for the source editor only. Import runs fetch directly.
 */
class FeedCache
{
    public const TTL = 900;
    private const STALE_FILE_AGE = 86400;

    private string $directory;
    /** @var callable */
    private $fetcher;
    /** @var callable */
    private $clock;

    public function __construct(?string $directory = null, ?callable $fetcher = null, ?callable $clock = null)
    {
        if ($directory === null) {
            $directory = (defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir() . '/') . 'productimport/';
        }
        $this->directory = rtrim($directory, '/') . '/';
        $this->fetcher = $fetcher ?? static function (array $source): array {
            return FeedFetcher::fetch($source);
        };
        $this->clock = $clock ?? 'time';
    }

    public static function key(array $source): string
    {
        $name = (string) ($source['source_format'] ?? 'json');
        $parts = [$name, trim((string) ($source['location'] ?? ''))];
        if (FeedFormats::has($name)) {
            foreach (array_keys(FeedFormats::get($name)->options()) as $option) {
                $parts[] = $option . '=' . trim((string) ($source[$option] ?? ''));
            }
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @return array{items: array, fetched_at: int, age: int, cached: bool}
     */
    public function get(array $source, bool $refresh = false): array
    {
        return $this->load('feed-', $source, $refresh, $this->fetcher);
    }

    /**
     * Candidate xml_item_path values of an XML source, cached under the same TTL. `items` holds the candidates.
     * The key ignores xml_item_path: detection depends only on the format and location.
     *
     * @return array{items: array, fetched_at: int, age: int, cached: bool}
     */
    public function itemPaths(array $source, bool $refresh = false): array
    {
        $source = ['source_format' => 'xml', 'location' => $source['location'] ?? ''];

        return $this->load('feed-paths-', $source, $refresh, static function (array $source): array {
            return (new XmlFormat())->detectItemPaths(FeedFetcher::fetchBody($source));
        });
    }

    private function load(string $prefix, array $source, bool $refresh, callable $fetcher): array
    {
        $now = (int) ($this->clock)();
        $file = $this->directory . $prefix . self::key($source) . '.json';
        if (!$refresh) {
            $hit = $this->read($file, $now);
            if ($hit !== null) {
                return $hit;
            }
        }
        $items = $fetcher($source);
        $this->write($file, $items, $now);

        return ['items' => $items, 'fetched_at' => $now, 'age' => 0, 'cached' => false];
    }

    private function read(string $file, int $now): ?array
    {
        $json = is_file($file) ? @file_get_contents($file) : false;
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['fetched_at'] ?? null) || !is_array($data['items'] ?? null)) {
            return null;
        }
        $age = $now - $data['fetched_at'];
        if ($age < 0 || $age >= self::TTL) {
            return null;
        }

        return ['items' => $data['items'], 'fetched_at' => $data['fetched_at'], 'age' => $age, 'cached' => true];
    }

    /** A failed write only costs a refetch next time, so it never fails the editor request. */
    private function write(string $file, array $items, int $now): void
    {
        $json = json_encode(['fetched_at' => $now, 'items' => $items], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        if ($json === false || (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory))) {
            return;
        }
        $temporary = @tempnam($this->directory, 'tmp');
        if ($temporary === false) {
            return;
        }
        if (@file_put_contents($temporary, $json) === false || !@rename($temporary, $file)) {
            @unlink($temporary);

            return;
        }
        @chmod($file, 0664);
        foreach (glob($this->directory . 'feed-*.json') ?: [] as $stale) {
            if ($stale !== $file && time() - (int) @filemtime($stale) > self::STALE_FILE_AGE) {
                @unlink($stale);
            }
        }
    }
}
