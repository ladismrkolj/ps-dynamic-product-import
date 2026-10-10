<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\AttributeResolver;
use ProductImport\Service\BackgroundImport;
use ProductImport\Service\CategoryResolver;
use ProductImport\Service\FeedDownloader;
use ProductImport\Service\JsonFetcher;
use ProductImport\Service\ManufacturerResolver;
use ProductImport\Service\XmlFetcher;

class FeedDownloaderTest extends TestCase
{
    #[DataProvider('classification')]
    public function testRetryClassification(int $status, ?string $error, bool $tooLarge, bool $expected): void
    {
        self::assertSame($expected, FeedDownloader::isRetryable($status, $error, $tooLarge));
    }

    public static function classification(): array
    {
        return [
            'network error' => [0, 'Connection reset', false, true],
            'http 500' => [500, null, false, true],
            'http 503' => [503, null, false, true],
            'http 429' => [429, null, false, true],
            'http 404' => [404, null, false, false],
            'http 400' => [400, null, false, false],
            'http 301' => [301, null, false, false],
            'too large' => [200, 'write error', true, false],
        ];
    }

    private function transport(array &$calls, array $responses): callable
    {
        return static function (string $url, int $max) use (&$calls, &$responses): array {
            ++$calls['n'];
            return array_shift($responses);
        };
    }

    private static function ok(string $body): array
    {
        return ['status' => 200, 'body' => $body, 'error' => null, 'too_large' => false];
    }

    public function testRetriesAfterOneInjectedFailure(): void
    {
        $calls = ['n' => 0];
        $sleeps = [];
        $downloader = new FeedDownloader(1000, [2, 5], static function (int $s) use (&$sleeps): void {
            $sleeps[] = $s;
        }, $this->transport($calls, [
            ['status' => 503, 'body' => '', 'error' => null, 'too_large' => false],
            self::ok('[]'),
        ]));
        self::assertSame([], (new JsonFetcher($downloader))->fetch('http://feed', null));
        self::assertSame(2, $calls['n']);
        self::assertSame([2], $sleeps);
    }

    public function testGivesUpAfterTwoRetries(): void
    {
        $calls = ['n' => 0];
        $fail = ['status' => 0, 'body' => '', 'error' => 'timeout', 'too_large' => false];
        $downloader = new FeedDownloader(1000, [2, 5], static function (int $s): void {
        }, $this->transport($calls, [$fail, $fail, $fail, $fail]));
        try {
            (new XmlFetcher($downloader))->fetch('http://feed', null, 'a');
            self::fail('Expected exception');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('timeout', $error->getMessage());
        }
        self::assertSame(3, $calls['n']);
    }

    public function testNotFoundIsNotRetried(): void
    {
        $calls = ['n' => 0];
        $downloader = new FeedDownloader(1000, [2, 5], static function (int $s): void {
        }, $this->transport($calls, [['status' => 404, 'body' => '', 'error' => null, 'too_large' => false], self::ok('[]')]));
        try {
            (new JsonFetcher($downloader))->fetch('http://feed', null);
            self::fail('Expected exception');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('HTTP 404', $error->getMessage());
        }
        self::assertSame(1, $calls['n']);
    }

    public function testOversizedDownloadIsRejectedWithoutRetry(): void
    {
        $calls = ['n' => 0];
        $downloader = new FeedDownloader(1000, [2, 5], static function (int $s): void {
        }, $this->transport($calls, [['status' => 200, 'body' => '', 'error' => null, 'too_large' => true], self::ok('[]')]));
        try {
            (new JsonFetcher($downloader))->fetch('http://feed', null);
            self::fail('Expected exception');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('size limit', $error->getMessage());
        }
        self::assertSame(1, $calls['n']);
    }

    public function testLocalFileIsSubjectToSizeCap(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pi_cap_');
        try {
            file_put_contents($file, '["' . str_repeat('x', 100) . '"]');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('size limit');
            (new JsonFetcher(new FeedDownloader(50)))->fetch(null, $file);
        } finally {
            unlink($file);
        }
    }

    public function testCacheKeysSeparateCommitModeAndSource(): void
    {
        self::assertNotSame(CategoryResolver::cacheKey(1, null, null, true, 'h'), CategoryResolver::cacheKey(1, null, null, false, 'h'));
        self::assertNotSame(CategoryResolver::cacheKey(1, null, null, true, 'h'), CategoryResolver::cacheKey(2, null, null, true, 'h'));
        self::assertNotSame(CategoryResolver::cacheKey(1, null, null, true, 'h'), CategoryResolver::cacheKey(1, 5, null, true, 'h'));
        self::assertNotSame(ManufacturerResolver::cacheKey(1, null, true, 'A'), ManufacturerResolver::cacheKey(1, null, false, 'A'));
        self::assertNotSame(ManufacturerResolver::cacheKey(1, 3, true, 'A'), ManufacturerResolver::cacheKey(1, null, true, 'A'));
        self::assertNotSame(AttributeResolver::cacheKey('a', 'b', true), AttributeResolver::cacheKey('a', 'b', false));
        self::assertNotSame(AttributeResolver::cacheKey('a|b', 'c', true), AttributeResolver::cacheKey('a', 'b|c', true));
    }

    public function testStartingMarkerStaleness(): void
    {
        self::assertFalse(BackgroundImport::isMarkerStale(1000, 1060));
        self::assertTrue(BackgroundImport::isMarkerStale(1000, 1061));
    }
}
