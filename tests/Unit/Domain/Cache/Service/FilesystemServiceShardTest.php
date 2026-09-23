<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Support\Config;

/**
 * Cache files are sharded by `{namespace}:{type}` so clearing a whole type —
 * which happens on every collection write for the `api` type — drops one
 * directory instead of unserializing every file in the cache.
 */
final class FilesystemServiceShardTest extends TestCase
{
	private string $root = '';
	private FilesystemService $service;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-fs-shard-' . uniqid();
		mkdir($this->root . '/cache', 0755, true);

		$config           = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->cachedir = $this->root . '/cache';
		$config->datadir  = $this->root . '/tcms-data';
		$config->cache    = ['filesystem' => true, 'domainScoped' => true];

		$this->service = new FilesystemService($config);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testEntriesOfOneTypeShareADirectoryOtherTypesDoNot(): void
	{
		$this->service->set('ns:api:one', 1);
		$this->service->set('ns:api:two', 2);
		$this->service->set('ns:computed:index:blog', 3);

		$shards = glob($this->root . '/cache/*', GLOB_ONLYDIR);

		$this->assertCount(2, $shards);
		$this->assertCount(2, glob($this->root . '/cache/' . $this->shard('ns:api') . '/*.cache'));
	}

	public function testClearingAWholeTypeDropsItsShardWithoutReadingTheOthers(): void
	{
		$this->service->set('ns:api:one', 1);
		$this->service->set('ns:computed:index:blog', 3);

		// An unreadable file in another shard would blow up a full walk.
		$other = $this->root . '/cache/' . $this->shard('ns:computed');
		file_put_contents($other . '/broken.cache', 'not serialized');
		chmod($other . '/broken.cache', 0000);

		$this->assertTrue($this->service->clearByPattern('ns:api:*'));

		$this->assertDirectoryDoesNotExist($this->root . '/cache/' . $this->shard('ns:api'));
		$this->assertNull($this->service->get('ns:api:one'));
		$this->assertSame(3, $this->service->get('ns:computed:index:blog'));
	}

	public function testANarrowerPatternOnlyWalksItsTypeShard(): void
	{
		$this->service->set('ns:computed:object:blog:1', 'b1');
		$this->service->set('ns:computed:object:news:1', 'n1');
		$this->service->set('ns:computed:index:blog', 'idx');

		$this->assertTrue($this->service->clearByPattern('ns:computed:object:blog:*'));

		$this->assertNull($this->service->get('ns:computed:object:blog:1'));
		$this->assertSame('n1', $this->service->get('ns:computed:object:news:1'));
		$this->assertSame('idx', $this->service->get('ns:computed:index:blog'));
	}

	public function testAPatternWithAWildcardTypeWalksEverything(): void
	{
		$this->service->set('ns:api:one', 1);
		$this->service->set('ns:computed:index:blog', 3);
		$this->service->set('other:api:one', 9);

		$this->assertTrue($this->service->clearByPattern('ns:*'));

		$this->assertNull($this->service->get('ns:api:one'));
		$this->assertNull($this->service->get('ns:computed:index:blog'));
		$this->assertSame(9, $this->service->get('other:api:one'));
	}

	private function shard(string $type): string
	{
		return substr(hash('sha256', $type), 0, 16);
	}
}
