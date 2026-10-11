<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Repository\CategoryMappingRepository;
use ProductImport\Repository\ManufacturerMappingRepository;
use ProductImport\Service\CategoryPathNormalizer;
use ProductImport\Service\CategoryResolver;
use ProductImport\Service\ManufacturerResolver;

class FakeCategoryRepository extends CategoryMappingRepository
{
    /** @var array<string,?int> */
    public array $rows = [];
    /** @var list<int> */
    public array $existing = [2];
    /** @var list<string> */
    public array $writes = [];

    public function upsertSeen(int $idSource, string $hash, string $sourcePath): void
    {
        $this->writes[] = 'seen';
        $this->rows[$hash] ??= null;
    }

    public function findOverride(int $idSource, string $hash): ?array
    {
        return array_key_exists($hash, $this->rows) ? ['id_category' => $this->rows[$hash]] : null;
    }

    public function setOverride(int $idSource, string $hash, ?int $idCategory): void
    {
        $this->writes[] = 'set:' . ($idCategory ?? 'null');
        $this->rows[$hash] = $idCategory;
    }

    public function categoryExists(int $idCategory): bool
    {
        return in_array($idCategory, $this->existing, true);
    }
}

class WalkingCategoryResolver extends CategoryResolver
{
    public function __construct(CategoryMappingRepository $mappings)
    {
        parent::__construct($mappings, new CategoryPathNormalizer());
    }

    protected function resolveChild(int $parent, string $name, bool $commit): ?int
    {
        return $commit ? 50 : null;
    }
}

class FakeManufacturerRepository extends ManufacturerMappingRepository
{
    /** @var array<string,?int> */
    public array $rows = [];
    /** @var list<int> */
    public array $existing = [];
    /** @var array<string,int> */
    public array $byName = [];
    /** @var list<string> */
    public array $writes = [];

    public function upsertSeen(int $idSource, string $name): void
    {
        $this->writes[] = 'seen';
        $this->rows[$name] ??= null;
    }

    public function findOverride(int $idSource, string $name): ?array
    {
        return array_key_exists($name, $this->rows) ? ['id_manufacturer' => $this->rows[$name]] : null;
    }

    public function setOverride(int $idSource, string $name, ?int $idManufacturer): void
    {
        $this->writes[] = 'set:' . ($idManufacturer ?? 'null');
        $this->rows[$name] = $idManufacturer;
    }

    public function manufacturerExists(int $idManufacturer): bool
    {
        return in_array($idManufacturer, $this->existing, true);
    }

    public function findIdByName(string $name): ?int
    {
        return $this->byName[$name] ?? null;
    }

    public function create(string $name): int
    {
        $this->writes[] = 'create';

        return $this->byName[$name] = 77;
    }
}

class MappingConnectTest extends TestCase
{
    private const PATH = [['Boards']];

    private function hash(): string
    {
        return (new CategoryPathNormalizer())->hash(['Boards']);
    }

    public function testCommitConnectsTheResolvedCategory(): void
    {
        $repository = new FakeCategoryRepository();
        $result = (new WalkingCategoryResolver($repository))->resolve(self::PATH, 1, 2, true, null);

        self::assertSame([50], $result);
        self::assertSame(50, $repository->rows[$this->hash()]);
    }

    public function testPreviewWritesNothingAndReportsAutoCreate(): void
    {
        $repository = new FakeCategoryRepository();
        $result = (new WalkingCategoryResolver($repository))->resolve(self::PATH, 1, 2, false, null);

        self::assertSame([['path' => ['Boards'], 'id_category' => null, 'auto_create' => true]], $result);
        self::assertSame([], $repository->writes);
    }

    public function testDefaultIsNotConnected(): void
    {
        $repository = new FakeCategoryRepository();
        $result = (new WalkingCategoryResolver($repository))->resolve(self::PATH, 1, 2, true, 9);

        self::assertSame([9], $result);
        self::assertNull($repository->rows[$this->hash()]);
    }

    public function testMissingCategoryIsLoggedAndReconnectedOnCommit(): void
    {
        $repository = new FakeCategoryRepository();
        $repository->rows[$this->hash()] = 7;
        $resolver = new WalkingCategoryResolver($repository);

        self::assertSame([50], $resolver->resolve(self::PATH, 1, 2, true, null));
        self::assertSame(50, $repository->rows[$this->hash()]);
        self::assertSame(['category "Boards": mapped category #7 no longer exists, mapping cleared and resolved again'], $resolver->drainNotices());
        self::assertSame([], $resolver->drainNotices());
    }

    public function testMissingCategoryWithDefaultClearsTheMapping(): void
    {
        $repository = new FakeCategoryRepository();
        $repository->rows[$this->hash()] = 7;

        self::assertSame([9], (new WalkingCategoryResolver($repository))->resolve(self::PATH, 1, 2, true, 9));
        self::assertNull($repository->rows[$this->hash()]);
    }

    public function testMissingCategoryInPreviewWritesNothing(): void
    {
        $repository = new FakeCategoryRepository();
        $repository->rows[$this->hash()] = 7;
        $resolver = new WalkingCategoryResolver($repository);

        self::assertSame([['path' => ['Boards'], 'id_category' => null, 'auto_create' => true]], $resolver->resolve(self::PATH, 1, 2, false, null));
        self::assertSame([], $repository->writes);
        self::assertSame(7, $repository->rows[$this->hash()]);
        self::assertSame([], $resolver->drainNotices());
    }

    public function testExistingMappingIsKept(): void
    {
        $repository = new FakeCategoryRepository();
        $repository->existing = [2, 7];
        $repository->rows[$this->hash()] = 7;

        self::assertSame([7], (new WalkingCategoryResolver($repository))->resolve(self::PATH, 1, 2, true, 9));
        self::assertSame(7, $repository->rows[$this->hash()]);
    }

    public function testBrandIsFoundAndConnectedOnCommit(): void
    {
        $repository = new FakeManufacturerRepository();
        $repository->byName['Acme'] = 4;

        self::assertSame(4, (new ManufacturerResolver($repository))->resolve('Acme', 1, true, null));
        self::assertSame(4, $repository->rows['Acme']);
    }

    public function testBrandIsCreatedAndConnectedOnCommit(): void
    {
        $repository = new FakeManufacturerRepository();

        self::assertSame(77, (new ManufacturerResolver($repository))->resolve('Acme', 1, true, null));
        self::assertSame(77, $repository->rows['Acme']);
    }

    public function testBrandPreviewWritesNothing(): void
    {
        $repository = new FakeManufacturerRepository();

        self::assertSame(['name' => 'Acme', 'id_manufacturer' => null, 'auto_create' => true], (new ManufacturerResolver($repository))->resolve('Acme', 1, false, null));
        self::assertSame([], $repository->writes);
    }

    public function testBrandDefaultIsNotConnected(): void
    {
        $repository = new FakeManufacturerRepository();

        self::assertSame(9, (new ManufacturerResolver($repository))->resolve('Acme', 1, true, 9));
        self::assertNull($repository->rows['Acme']);
    }

    public function testMissingBrandIsLoggedAndReconnected(): void
    {
        $repository = new FakeManufacturerRepository();
        $repository->rows['Acme'] = 7;
        $resolver = new ManufacturerResolver($repository);

        self::assertSame(77, $resolver->resolve('Acme', 1, true, null));
        self::assertSame(77, $repository->rows['Acme']);
        self::assertSame(['brand "Acme": mapped manufacturer #7 no longer exists, mapping cleared and resolved again'], $resolver->drainNotices());
    }

    public function testOverlongBrandNameIsResolvedWithoutARow(): void
    {
        $repository = new FakeManufacturerRepository();
        $name = str_repeat('x', 192);

        self::assertSame(9, (new ManufacturerResolver($repository))->resolve($name, 1, true, 9));
        self::assertSame([], $repository->writes);
    }
}
