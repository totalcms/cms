<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sitemap\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Sitemap\Service\SitemapIndexBuilder;
use TotalCMS\Support\Config;

/**
 * Tests for the sitemap index builder.
 *
 * Verifies that the index lists the pages sitemap (when builder pages exist)
 * and only those collections explicitly opted in via `sitemap.enabled === true`.
 */
final class SitemapIndexBuilderTest extends TestCase
{
	private SitemapIndexBuilder $builder;
	private \PHPUnit\Framework\MockObject\MockObject $mockCollectionLister;
	private \PHPUnit\Framework\MockObject\MockObject $mockBuilderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $config;

	protected function setUp(): void
	{
		$this->mockCollectionLister = $this->createMock(CollectionLister::class);
		$this->mockBuilderConfig    = $this->createMock(BuilderConfigService::class);
		$this->config               = $this->createMock(Config::class);
		$this->config->domain       = 'example.com';

		$this->builder = new SitemapIndexBuilder(
			$this->mockCollectionLister,
			$this->mockBuilderConfig,
			$this->config,
		);
	}

	public function testIncludesPagesSitemapWhenBuilderPagesExist(): void
	{
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(true);
		$this->mockCollectionLister->method('listAllCollections')->willReturn([]);

		$xml = $this->builder->buildIndex();

		expect($xml)->toContain('<sitemapindex');
		expect($xml)->toContain('https://example.com/sitemap/-pages');
	}

	public function testOmitsPagesSitemapWhenBuilderPagesMissing(): void
	{
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(false);
		$this->mockCollectionLister->method('listAllCollections')->willReturn([]);

		$xml = $this->builder->buildIndex();

		expect($xml)->not->toContain('/sitemap/-pages');
	}

	public function testIncludesEnabledCollectionSitemaps(): void
	{
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(false);
		$this->mockCollectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog', ['enabled' => true]),
			$this->makeCollection('products', ['enabled' => true]),
		]);

		$xml = $this->builder->buildIndex();

		expect($xml)->toContain('https://example.com/sitemap/blog');
		expect($xml)->toContain('https://example.com/sitemap/products');
	}

	public function testExcludesDisabledCollectionSitemaps(): void
	{
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(false);
		$this->mockCollectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog', ['enabled' => true]),
			$this->makeCollection('private', ['enabled' => false]),
			$this->makeCollection('untouched', []),
		]);

		$xml = $this->builder->buildIndex();

		expect($xml)->toContain('/sitemap/blog');
		expect($xml)->not->toContain('/sitemap/private');
		expect($xml)->not->toContain('/sitemap/untouched');
	}

	public function testEmptyIndexWhenNothingPublished(): void
	{
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(false);
		$this->mockCollectionLister->method('listAllCollections')->willReturn([]);

		$xml = $this->builder->buildIndex();

		expect($xml)->toContain('<sitemapindex');
		expect($xml)->not->toContain('<sitemap>');
	}

	public function testIncludesPagesAndCollectionsTogether(): void
	{
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(true);
		$this->mockCollectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog', ['enabled' => true]),
		]);

		$xml = $this->builder->buildIndex();

		expect($xml)->toContain('/sitemap/-pages');
		expect($xml)->toContain('/sitemap/blog');
	}

	/**
	 * @param array<string,mixed> $sitemapSettings
	 */
	private function makeCollection(string $id, array $sitemapSettings): CollectionData
	{
		$collection          = new CollectionData();
		$collection->id      = $id;
		$collection->name    = ucfirst($id);
		$collection->schema  = $id;
		$collection->sitemap = $sitemapSettings;

		return $collection;
	}
}
