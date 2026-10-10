<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\FeedFetcher;
use ProductImport\Service\Format\JsonFormat;

class JsonFormatTest extends TestCase
{
    public function testParsesRepresentativeProducts(): void
    {
        $json = file_get_contents(__DIR__ . '/fixtures/products.json');
        $items = (new JsonFormat())->parse($json, []);
        self::assertSame(json_decode($json, true), $items);
        self::assertCount(3, $items);
        self::assertSame(249.5, $items[0]['price']);
    }

    public function testEmptyListIsValid(): void
    {
        self::assertSame([], (new JsonFormat())->parse('[]', []));
    }

    #[DataProvider('invalidJson')]
    public function testRejectsInvalidJsonOrNonList(string $body, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new JsonFormat())->parse($body, []);
    }

    public static function invalidJson(): array
    {
        return [
            ['{broken', 'Invalid JSON'], ['', 'Invalid JSON'],
            ['null', 'top-level array'], ['42', 'top-level array'],
            ['"text"', 'top-level array'], ['false', 'top-level array'],
            ['{}', 'top-level array'], ['{"0":{"name":"item"}}', 'top-level array'],
        ];
    }

    public function testFetchReadsRelativeFileFromImportFolder(): void
    {
        $dir = sys_get_temp_dir() . '/pi_fetch_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/feed.json', '[{"id":1}]');
            self::assertSame([['id' => 1]], FeedFetcher::fetch(['location' => 'feed.json', 'source_format' => 'json'], null, $dir));
        } finally {
            @unlink($dir . '/feed.json');
            rmdir($dir);
        }
    }

    public function testFetchMissingFileThrows(): void
    {
        $dir = sys_get_temp_dir() . '/pi_fetch_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not a readable regular file');
            FeedFetcher::fetch(['location' => 'nope.json', 'source_format' => 'json'], null, $dir);
        } finally {
            rmdir($dir);
        }
    }

    public function testFetchRejectsUnknownFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedFetcher::fetch(['location' => 'https://x.test/a', 'source_format' => 'csv']);
    }
}
