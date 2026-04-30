<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sitemap\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Query\Service\ObjectFilter;
use TotalCMS\Domain\Sitemap\Exception\SitemapDisabledException;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Support\Config;

/**
 * Tests that SitemapBuilder reads saved sitemap card settings as defaults
 * and lets query-string options override them. Also verifies the disabled-gate
 * behavior — disabled collections throw SitemapDisabledException so the action
 * can return 404.
 */
final class SitemapBuilderSettingsTest extends TestCase
{
	private SitemapBuilder $builder;
	private IndexFilter $indexFilter;
	private \PHPUnit\Framework\MockObject\MockObject $mockIndexReader;
	private \PHPUnit\Framework\MockObject\MockObject $mockCollectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectUrlBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $mockConfig;

	protected function setUp(): void
	{
		$this->mockIndexReader       = $this->createMock(IndexReader::class);
		$this->indexFilter           = new IndexFilter($this->mockIndexReader, new ObjectFilter());
		$this->mockCollectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->mockObjectUrlBuilder  = $this->createMock(ObjectUrlBuilder::class);
		$this->mockConfig            = $this->createMock(Config::class);

		$this->mockConfig->domain = 'example.com';

		$this->mockObjectUrlBuilder
			->method('buildUrl')
			->willReturnCallback(fn (CollectionData $c, array $object): string => '/blog/' . $object['id']);
		$this->mockObjectUrlBuilder->method('hasEmptySegments')->willReturn(false);

		$this->builder = new SitemapBuilder(
			$this->indexFilter,
			$this->mockCollectionFetcher,
			$this->mockObjectUrlBuilder,
			$this->mockConfig,
		);
	}

	public function testThrowsWhenSitemapNotEnabled(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => false],
			[['id' => 'post1']],
		);

		$this->expectException(SitemapDisabledException::class);
		$this->builder->buildSitemap('blog');
	}

	public function testThrowsWhenSitemapMissingEntirely(): void
	{
		$this->setupCollectionAndObjects(
			[],
			[['id' => 'post1']],
		);

		$this->expectException(SitemapDisabledException::class);
		$this->builder->buildSitemap('blog');
	}

	public function testEnabledCollectionRendersSitemap(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true],
			[['id' => 'post1']],
		);

		$xml = $this->builder->buildSitemap('blog');
		expect($xml)->toContain('post1');
	}

	public function testSavedFrequencyAppliedAsDefault(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true, 'frequency' => 'weekly'],
			[['id' => 'post1']],
		);

		$xml = $this->builder->buildSitemap('blog');
		expect($xml)->toContain('<changefreq>weekly</changefreq>');
	}

	public function testSavedPriorityAppliedAsDefault(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true, 'priority' => 0.7],
			[['id' => 'post1']],
		);

		$xml = $this->builder->buildSitemap('blog');
		expect($xml)->toContain('<priority>0.7</priority>');
	}

	public function testQueryParamFrequencyOverridesSavedDefault(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true, 'frequency' => 'weekly'],
			[['id' => 'post1']],
		);

		$xml = $this->builder->buildSitemap('blog', ['frequency' => 'daily']);
		expect($xml)->toContain('<changefreq>daily</changefreq>');
		expect($xml)->not->toContain('weekly');
	}

	public function testSavedExcludeFilterAppliedAsDefault(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true, 'exclude' => 'draft:true'],
			[
				['id' => 'live',  'draft' => false],
				['id' => 'draft', 'draft' => true],
			],
		);

		$xml = $this->builder->buildSitemap('blog');
		expect($xml)->toContain('live');
		expect($xml)->not->toContain('/blog/draft');
	}

	public function testEmptyPriorityNotEmitted(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true, 'priority' => 0],
			[['id' => 'post1']],
		);

		$xml = $this->builder->buildSitemap('blog');
		expect($xml)->not->toContain('<priority>');
	}

	public function testEmptyFrequencyNotEmitted(): void
	{
		$this->setupCollectionAndObjects(
			['enabled' => true, 'frequency' => ''],
			[['id' => 'post1']],
		);

		$xml = $this->builder->buildSitemap('blog');
		expect($xml)->not->toContain('<changefreq>');
	}

	/**
	 * @param array<string,mixed>           $sitemapSettings
	 * @param array<int,array<string,mixed>> $objects
	 */
	private function setupCollectionAndObjects(array $sitemapSettings, array $objects): void
	{
		$collectionData          = new CollectionData();
		$collectionData->id      = 'blog';
		$collectionData->name    = 'Blog';
		$collectionData->schema  = 'blog';
		$collectionData->url     = '/blog';
		$collectionData->sitemap = $sitemapSettings;

		$this->mockCollectionFetcher
			->method('fetchCollection')
			->willReturn($collectionData);

		$indexData          = new IndexData();
		$indexData->objects = new Collection($objects);
		$this->mockIndexReader->method('fetchIndex')->willReturn($indexData);
	}
}
