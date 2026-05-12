<?php

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Support\Config;

final class BuilderConfigServiceTest extends TestCase
{
	private BuilderConfigService $service;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;

	protected function setUp(): void
	{
		$this->config            = $this->createMock(Config::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);

		$this->config->builder = [];
		$this->config->docroot = '/var/www/html';

		$this->service = new BuilderConfigService(
			$this->config,
			$this->collectionFetcher,
		);
	}

	// --- getPagesCollectionId ---

	public function testDefaultPagesCollectionId(): void
	{
		$this->assertSame('builder-pages', $this->service->getPagesCollectionId());
	}

	public function testCustomPagesCollectionId(): void
	{
		$this->config->builder = ['pagesCollection' => 'my-pages'];

		$this->assertSame('my-pages', $this->service->getPagesCollectionId());
	}

	public function testFallsBackToDefaultForEmptyConfig(): void
	{
		$this->config->builder = ['pagesCollection' => ''];

		$this->assertSame('builder-pages', $this->service->getPagesCollectionId());
	}

	// --- pagesCollectionExists ---

	public function testPagesCollectionExistsReturnsTrue(): void
	{
		$this->collectionFetcher->method('collectionExists')
			->with('builder-pages')
			->willReturn(true);

		$this->assertTrue($this->service->pagesCollectionExists());
	}

	public function testPagesCollectionExistsReturnsFalse(): void
	{
		$this->collectionFetcher->method('collectionExists')
			->with('builder-pages')
			->willReturn(false);

		$this->assertFalse($this->service->pagesCollectionExists());
	}

	// --- getDocroot ---

	public function testGetDocroot(): void
	{
		$this->assertSame('/var/www/html', $this->service->getDocroot());
	}

	// --- getAssetsPath ---

	public function testAssetsPathDefaultsToAssets(): void
	{
		$this->assertSame('assets', $this->service->getAssetsPath());
	}

	public function testCustomAssetsPath(): void
	{
		$this->config->builder = ['assetsPath' => 'public-assets'];

		$this->assertSame('public-assets', $this->service->getAssetsPath());
	}

	public function testEmptyAssetsPathFallsBackToDefault(): void
	{
		$this->config->builder = ['assetsPath' => ''];

		$this->assertSame('assets', $this->service->getAssetsPath());
	}
}
