<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\FeedCache;

class FeedCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pi-feed-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testFetchesOnceWithinTtlThenRefetches(): void
    {
        $now = 1000;
        $calls = 0;
        $cache = new FeedCache($this->directory, function () use (&$calls) {
            ++$calls;

            return [['n' => $calls, 'price' => 1.0]];
        }, function () use (&$now) {
            return $now;
        });
        $source = ['source_format' => 'json', 'json_url' => 'https://example.test/feed.json'];
        $first = $cache->get($source);
        self::assertFalse($first['cached']);
        $now = 1000 + 60;
        $second = $cache->get($source);
        self::assertTrue($second['cached']);
        self::assertSame(60, $second['age']);
        self::assertSame(1000, $second['fetched_at']);
        self::assertSame($first['items'], $second['items']);
        self::assertSame(1, $calls);
        self::assertSame([], glob($this->directory . '/tmp*') ?: []);
        $now = 1000 + FeedCache::TTL;
        self::assertFalse($cache->get($source)['cached']);
        self::assertSame(2, $calls);
    }

    public function testRefreshBypassesAndKeyDependsOnSourceLocation(): void
    {
        $calls = 0;
        $cache = new FeedCache($this->directory, function () use (&$calls) {
            return [['n' => ++$calls]];
        }, static function () {
            return 5;
        });
        $a = ['source_format' => 'json', 'json_url' => 'https://example.test/a.json'];
        $cache->get($a);
        self::assertSame(2, $cache->get($a, true)['items'][0]['n']);
        self::assertTrue($cache->get($a)['cached']);
        self::assertFalse($cache->get(['source_format' => 'json', 'json_url' => 'https://example.test/b.json'])['cached']);
        self::assertFalse($cache->get($a + ['source_format' => 'xml', 'xml_item_path' => 'a/b'])['cached']);
        self::assertNotSame(FeedCache::key($a), FeedCache::key($a + ['json_file_path' => '/tmp/x']));
    }

    public function testCorruptCacheFileIsRefetched(): void
    {
        $cache = new FeedCache($this->directory, static function () {
            return [['ok' => true]];
        }, static function () {
            return 5;
        });
        $source = ['source_format' => 'json', 'json_file_path' => '/tmp/feed.json'];
        $cache->get($source);
        file_put_contents($this->directory . '/feed-' . FeedCache::key($source) . '.json', '{broken');
        self::assertFalse($cache->get($source)['cached']);
    }
}
