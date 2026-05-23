<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Data;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Round-trip regression: mcp.tools array must survive
 * CollectionFactory::generateCollection() → CollectionData::toJson() →
 * CollectionRepository::saveCollection() → fetchCollection().
 *
 * CollectionData::$mcp is typed as array<string,mixed> and serialized
 * as a plain JSON object. The tools sub-key is arbitrary nested data
 * that the Symfony ObjectNormalizer must preserve verbatim.
 */
final class CollectionMcpToolsRoundTripTest extends TestCase
{
	private string $tmpRoot;
	private CollectionRepository $repo;
	private CollectionFactory $factory;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-mcp-tools-' . uniqid();
		mkdir($this->tmpRoot, 0755, true);

		$flysystem = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage   = new StorageFilesystemAdapter($flysystem);

		// SchemaValidator and CacheManager are mocked — we only care about
		// serialize→write→read→deserialize fidelity, not schema compliance.
		$validator = $this->createMock(SchemaValidator::class);
		$validator->method('validateSchema')->willReturn(true);

		$cache = $this->createMock(CacheManager::class);
		$cache->method('getComputedData')->willReturn(null);
		$cache->method('storeComputedData')->willReturn(true);
		$cache->method('clearComputedData')->willReturn(true);
		$cache->method('clearCollectionIndex')->willReturn(true);

		$this->factory = new CollectionFactory();
		$this->repo    = new CollectionRepository(
			$storage,
			$this->factory,
			$validator,
			$cache,
			$this->createMock(IndexRepository::class),
		);
	}

	protected function tearDown(): void
	{
		$this->removeRecursively($this->tmpRoot);
	}

	public function testMcpToolsArrayPersistedThroughSaveAndReload(): void
	{
		$meta = [
			'id'     => 'roundtrip-tools-fixture',
			'name'   => 'Round-trip MCP Tools Fixture',
			'schema' => 'blog',
			'mcp'    => [
				'access'      => 'public',
				'description' => 'Fixture for mcp.tools persistence.',
				'tools'       => [
					[
						'name'        => 'find_featured',
						'description' => 'Return featured items.',
						'params'      => [
							'limit_override' => ['type' => 'integer', 'minimum' => 1],
						],
						'filters' => [
							'featured' => ['value' => true],
						],
						'limit'  => 10,
						'format' => 'markdown',
					],
				],
			],
		];

		$collection = $this->factory->generateCollection($meta);
		$this->repo->saveCollection($collection);

		$loaded = $this->repo->fetchCollection('roundtrip-tools-fixture');

		$this->assertInstanceOf(CollectionData::class, $loaded);
		$this->assertArrayHasKey('tools', $loaded->mcp);
		$this->assertCount(1, $loaded->mcp['tools']);
		$this->assertSame('find_featured', $loaded->mcp['tools'][0]['name']);
		$this->assertSame('integer', $loaded->mcp['tools'][0]['params']['limit_override']['type']);
	}

	public function testMcpToolsEmptyArrayRoundTrips(): void
	{
		$meta = [
			'id'     => 'roundtrip-tools-empty',
			'name'   => 'Round-trip MCP Tools Empty Fixture',
			'schema' => 'blog',
			'mcp'    => [
				'access' => 'admin',
				'tools'  => [],
			],
		];

		$collection = $this->factory->generateCollection($meta);
		$this->repo->saveCollection($collection);

		$loaded = $this->repo->fetchCollection('roundtrip-tools-empty');

		$this->assertInstanceOf(CollectionData::class, $loaded);
		// mcp.tools=[] is serialized and, when reloaded, mcp block is still present.
		$this->assertArrayHasKey('access', $loaded->mcp);
		$this->assertSame([], $loaded->mcp['tools'] ?? null);
	}

	private function removeRecursively(string $path): void
	{
		if (!is_dir($path)) {
			if (is_file($path) || is_link($path)) {
				@unlink($path);
			}

			return;
		}

		$entries = scandir($path);
		if ($entries === false) {
			return;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path . '/' . $entry;
			if (is_dir($child) && !is_link($child)) {
				$this->removeRecursively($child);
			} else {
				@unlink($child);
			}
		}

		@rmdir($path);
	}
}
