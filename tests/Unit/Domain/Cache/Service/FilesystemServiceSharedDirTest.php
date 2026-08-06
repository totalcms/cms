<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Support\Config;

/**
 * In shared mode the filesystem cache moves into tcms-data so installs sharing
 * one data folder share the disk layer too — otherwise each install keeps
 * answering from its own stale file after a sibling invalidates a key.
 */
final class FilesystemServiceSharedDirTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-fs-' . uniqid();
		mkdir($this->root . '/site/cache', 0755, true);
		mkdir($this->root . '/tcms-data/.system', 0755, true);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	private function makeConfig(bool $domainScoped): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		$config->cachedir = $this->root . '/site/cache';
		$config->datadir  = $this->root . '/tcms-data';
		$config->cache    = ['filesystem' => true, 'domainScoped' => $domainScoped];

		return $config;
	}

	public function testScopedModeUsesTheProjectCacheDirectory(): void
	{
		$service = new FilesystemService($this->makeConfig(true));

		$this->assertSame($this->root . '/site/cache', $service->getCachDir());
	}

	public function testSharedModeUsesTheDataDirectory(): void
	{
		$service = new FilesystemService($this->makeConfig(false));

		$this->assertSame($this->root . '/tcms-data/.system/cache', $service->getCachDir());
	}

	public function testSharedModeStoresEntriesInTheDataDirectory(): void
	{
		$service = new FilesystemService($this->makeConfig(false));

		$service->set('collection:blog', ['a' => 1], 60);

		$this->assertSame(['a' => 1], $service->get('collection:blog'));
		$this->assertNotEmpty(glob($this->root . '/tcms-data/.system/cache/*/*.cache'));
		$this->assertEmpty(glob($this->root . '/site/cache/*/*.cache'));
	}

	public function testClearAlsoWipesTheLocalDirectoryInSharedMode(): void
	{
		// Twig compiles templates into $config->cachedir with auto_reload off in
		// production, so "clear all caches" has to keep dropping compiled
		// templates even after the entry directory moves away.
		$service = new FilesystemService($this->makeConfig(false));
		$service->set('collection:blog', ['a' => 1], 60);

		$compiled = $this->root . '/site/cache/twig-compiled.php';
		file_put_contents($compiled, '<?php // compiled template');

		$service->clear();

		$this->assertFileDoesNotExist($compiled);
		$this->assertEmpty(glob($this->root . '/tcms-data/.system/cache/*/*.cache'));
	}

	public function testClearWipesTheSingleDirectoryInScopedMode(): void
	{
		$service = new FilesystemService($this->makeConfig(true));
		$service->set('collection:blog', ['a' => 1], 60);

		$this->assertTrue($service->clear());
		$this->assertEmpty(glob($this->root . '/site/cache/*/*.cache'));
	}
}
