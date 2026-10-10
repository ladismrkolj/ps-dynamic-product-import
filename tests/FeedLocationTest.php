<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\FeedLocation;

class FeedLocationTest extends TestCase
{
    private string $base;
    private string $feeds;
    private string $outside;

    protected function setUp(): void
    {
        $this->base = realpath(sys_get_temp_dir()) . '/pi_loc_' . bin2hex(random_bytes(4));
        $this->feeds = $this->base . '/feeds';
        $this->outside = $this->base . '/outside';
        mkdir($this->feeds . '/sub', 0777, true);
        mkdir($this->outside);
        file_put_contents($this->feeds . '/products.json', '[]');
        file_put_contents($this->feeds . '/sub/a.json', '[]');
        file_put_contents($this->outside . '/secret.json', '[]');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isLink() || $entry->isFile() ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
        }
        rmdir($this->base);
    }

    #[DataProvider('urls')]
    public function testHttpAndHttpsAreUrls(string $url): void
    {
        self::assertSame(['type' => 'url', 'value' => $url], FeedLocation::resolve("  $url  ", $this->feeds));
    }

    public static function urls(): array
    {
        return [['http://example.test/feed.json'], ['https://example.test/a/b.xml?x=1'], ['HTTPS://Example.test/f']];
    }

    #[DataProvider('rejected')]
    public function testOtherSchemesAndBadInputAreRejected(string $location): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedLocation::resolve($location, $this->feeds);
    }

    public static function rejected(): array
    {
        return [['file:///etc/passwd'], ['php://filter/resource=x'], ['ftp://example.test/f'], ['data:text/plain,x'], ['gopher://x'], ['http://'], ['https:///path'], [''], ['   '], ["a\0.json"]];
    }

    public function testRelativePathResolvesInsideFolder(): void
    {
        self::assertSame(['type' => 'file', 'value' => $this->feeds . '/products.json'], FeedLocation::resolve('products.json', $this->feeds));
        self::assertSame(['type' => 'file', 'value' => $this->feeds . '/sub/a.json'], FeedLocation::resolve('sub/a.json', $this->feeds . '/'));
    }

    public function testMissingFileIsAllowedAtResolveTime(): void
    {
        self::assertSame($this->feeds . '/later/new.json', FeedLocation::resolve('later/new.json', $this->feeds)['value']);
    }

    public function testAbsolutePathInsideAndOutside(): void
    {
        self::assertSame($this->feeds . '/products.json', FeedLocation::resolve($this->feeds . '/products.json', $this->feeds)['value']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inside the import folder');
        FeedLocation::resolve($this->outside . '/secret.json', $this->feeds);
    }

    #[DataProvider('traversals')]
    public function testTraversalIsRejected(string $location): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedLocation::resolve($location, $this->feeds);
    }

    public static function traversals(): array
    {
        return [['../outside/secret.json'], ['sub/../../outside/secret.json'], ['../outside/missing.json'], ['missing/../../outside/x.json'], ['/etc/passwd']];
    }

    public function testInnerDotDotThatStaysInsideIsAllowed(): void
    {
        self::assertSame($this->feeds . '/products.json', FeedLocation::resolve('sub/../products.json', $this->feeds)['value']);
    }

    public function testSiblingFolderWithSharedPrefixIsRejected(): void
    {
        mkdir($this->base . '/feeds-evil');
        $this->expectException(\InvalidArgumentException::class);
        FeedLocation::resolve($this->base . '/feeds-evil/x.json', $this->feeds);
    }

    public function testSymlinkPointingOutsideIsRejected(): void
    {
        symlink($this->outside . '/secret.json', $this->feeds . '/link.json');
        symlink($this->outside, $this->feeds . '/linkdir');
        foreach (['link.json', 'linkdir/secret.json', 'linkdir/new.json', $this->feeds . '/link.json'] as $location) {
            try {
                FeedLocation::resolve($location, $this->feeds);
                self::fail("Expected rejection for $location");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSymlinkInsideFolderIsAllowed(): void
    {
        symlink($this->feeds . '/products.json', $this->feeds . '/alias.json');
        self::assertSame($this->feeds . '/products.json', FeedLocation::resolve('alias.json', $this->feeds)['value']);
    }

    public function testMissingImportFolderIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import folder');
        FeedLocation::resolve('products.json', $this->base . '/nope');
    }
}
