<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Repository;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * One resolution chain — default → extension → custom — behind getSchema()
 * and schemaExists(), which used to spell it out separately.
 */
final class SchemaRepositoryResolveTest extends TestCase
{
	private string $root = '';
	private SchemaRepository $repo;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-schema-' . uniqid();
		mkdir($this->root . '/.schemas', 0755, true);
		mkdir($this->root . '/ext-schemas', 0755, true);

		$text       = json_decode((string)file_get_contents(SchemaRepository::defaultSchemaDir() . 'text.json'), true);
		$text['id'] = 'widget';
		file_put_contents($this->root . '/.schemas/widget.json', json_encode($text));
		$text['id'] = 'gadget';
		file_put_contents($this->root . '/ext-schemas/gadget.json', json_encode($text));
		file_put_contents($this->root . '/.schemas/broken.json', '{not json');

		$cache = $this->createMock(CacheManager::class);
		$cache->method('getComputedData')->willReturn(null);
		$cache->method('storeComputedData')->willReturn(true);

		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->root;

		$this->repo = new SchemaRepository(
			new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->root))),
			new SchemaFactory(),
			$cache,
			$config,
		);
		$this->repo->registerExtensionSchemaDir($this->root . '/ext-schemas');
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testEveryTierResolvesThroughBothEntryPoints(): void
	{
		foreach (['text' => 'default', 'gadget' => 'extension', 'widget' => 'custom'] as $id => $tier) {
			$this->assertTrue($this->repo->schemaExists($id), "$tier exists");
			$this->assertSame($id, $this->repo->getSchema($id)->id, "$tier resolves");
		}
	}

	public function testAnUnknownIdIsAbsentAndGetSchemaThrows(): void
	{
		$this->assertFalse($this->repo->schemaExists('nope'));
		$this->assertFalse($this->repo->schemaIsUnreadable('nope'));

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('Schema type does not exist: nope');
		$this->repo->getSchema('nope');
	}

	public function testACorruptFileReadsAsAbsentButUnreadable(): void
	{
		$this->assertFalse($this->repo->schemaExists('broken'));
		$this->assertTrue($this->repo->schemaIsUnreadable('broken'));
	}
}
