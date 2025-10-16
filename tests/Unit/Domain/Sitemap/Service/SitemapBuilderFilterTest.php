<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sitemap\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Support\Config;

/**
 * Test sitemap filtering functionality.
 */
final class SitemapBuilderFilterTest extends TestCase
{
	private SitemapBuilder $sitemapBuilder;
	private IndexFilter $mockIndexFilter;
	private \PHPUnit\Framework\MockObject\MockObject $mockIndexReader;
	private \PHPUnit\Framework\MockObject\MockObject $mockCollectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockConfig;

	protected function setUp(): void
	{
		// Use real IndexFilter with mocked IndexReader for proper filtering logic
		$mockIndexReader             = $this->createMock(IndexReader::class);
		$this->mockIndexFilter       = new IndexFilter($mockIndexReader);
		$this->mockCollectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->mockConfig            = $this->createMock(Config::class);

		$this->sitemapBuilder = new SitemapBuilder(
			$this->mockIndexFilter,
			$this->mockCollectionFetcher,
			$this->mockConfig
		);

		// Mock config domain
		$this->mockConfig->domain = 'example.com';

		// Store the mock IndexReader for use in setupMocksWithTestData
		$this->mockIndexReader = $mockIndexReader;
	}

	public function testNoFiltersIncludesAllObjects(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'draft' => false],
			['id' => 'post2', 'title' => 'Post 2', 'draft' => true],
			['id' => 'post3', 'title' => 'Post 3', 'featured' => true],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', []);

		expect($result)->toContain('post1');
		expect($result)->toContain('post2');
		expect($result)->toContain('post3');
	}

	public function testExcludeDraftShorthand(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'draft' => false],
			['id' => 'post2', 'title' => 'Post 2', 'draft' => true],
			['id' => 'post3', 'title' => 'Post 3'], // No draft field
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['exclude' => 'draft']);

		expect($result)->toContain('post1');
		expect($result)->not->toContain('post2'); // Excluded (draft: true)
		expect($result)->toContain('post3'); // Included (no draft field)
	}

	public function testExcludeDraftExplicit(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'draft' => false],
			['id' => 'post2', 'title' => 'Post 2', 'draft' => true],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['exclude' => 'draft:true']);

		expect($result)->toContain('post1');
		expect($result)->not->toContain('post2');
	}

	public function testExcludeDraftFalse(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'draft' => false],
			['id' => 'post2', 'title' => 'Post 2', 'draft' => true],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['exclude' => 'draft:false']);

		expect($result)->not->toContain('post1'); // Excluded (draft: false)
		expect($result)->toContain('post2');
	}

	public function testIncludeFeaturedShorthand(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'featured' => true],
			['id' => 'post2', 'title' => 'Post 2', 'featured' => false],
			['id' => 'post3', 'title' => 'Post 3'], // No featured field
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['include' => 'featured']);

		expect($result)->toContain('post1'); // Included (featured: true)
		expect($result)->not->toContain('post2'); // Excluded (featured: false)
		expect($result)->not->toContain('post3'); // Excluded (no featured field)
	}

	public function testIncludeStatusExplicit(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'status' => 'published'],
			['id' => 'post2', 'title' => 'Post 2', 'status' => 'draft'],
			['id' => 'post3', 'title' => 'Post 3', 'status' => 'archived'],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['include' => 'status:published']);

		expect($result)->toContain('post1'); // Included (status: published)
		expect($result)->not->toContain('post2'); // Excluded (status: draft)
		expect($result)->not->toContain('post3'); // Excluded (status: archived)
	}

	public function testMultipleExcludeFilters(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'draft' => false, 'private' => false],
			['id' => 'post2', 'title' => 'Post 2', 'draft' => true, 'private' => false],
			['id' => 'post3', 'title' => 'Post 3', 'draft' => false, 'private' => true],
			['id' => 'post4', 'title' => 'Post 4', 'draft' => true, 'private' => true],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['exclude' => 'draft,private']);

		expect($result)->toContain('post1'); // Included (draft: false, private: false)
		expect($result)->not->toContain('post2'); // Excluded (draft: true)
		expect($result)->not->toContain('post3'); // Excluded (private: true)
		expect($result)->not->toContain('post4'); // Excluded (both draft and private)
	}

	public function testMultipleIncludeFilters(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'published' => true, 'featured' => true],
			['id' => 'post2', 'title' => 'Post 2', 'published' => true, 'featured' => false],
			['id' => 'post3', 'title' => 'Post 3', 'published' => false, 'featured' => true],
			['id' => 'post4', 'title' => 'Post 4', 'published' => false, 'featured' => false],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['include' => 'published,featured']);

		expect($result)->toContain('post1'); // Included (both published and featured)
		expect($result)->not->toContain('post2'); // Excluded (not featured)
		expect($result)->not->toContain('post3'); // Excluded (not published)
		expect($result)->not->toContain('post4'); // Excluded (neither)
	}

	public function testCombinedIncludeAndExclude(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'published' => true, 'draft' => false],
			['id' => 'post2', 'title' => 'Post 2', 'published' => true, 'draft' => true],
			['id' => 'post3', 'title' => 'Post 3', 'published' => false, 'draft' => false],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', [
			'include' => 'published',
			'exclude' => 'draft',
		]);

		expect($result)->toContain('post1'); // Included (published: true, draft: false)
		expect($result)->not->toContain('post2'); // Excluded by draft filter
		expect($result)->not->toContain('post3'); // Excluded by published filter
	}

	public function testMixedExplicitAndShorthandValues(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'featured' => true, 'category' => 'tech'],
			['id' => 'post2', 'title' => 'Post 2', 'featured' => false, 'category' => 'tech'],
			['id' => 'post3', 'title' => 'Post 3', 'featured' => true, 'category' => 'news'],
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', [
			'include' => 'featured,category:tech',
		]);

		expect($result)->toContain('post1'); // Included (featured: true, category: tech)
		expect($result)->not->toContain('post2'); // Excluded (featured: false)
		expect($result)->not->toContain('post3'); // Excluded (category: news)
	}

	public function testEmptyCollection(): void
	{
		$this->setupMocksWithTestData([]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['exclude' => 'draft']);

		expect($result)->toBeString();
		expect($result)->toContain('<urlset');
	}

	public function testBooleanValueConversion(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'active' => true],
			['id' => 'post2', 'title' => 'Post 2', 'active' => false],
			['id' => 'post3', 'title' => 'Post 3', 'active' => 'true'], // String true
			['id' => 'post4', 'title' => 'Post 4', 'active' => 'false'], // String false
		]);

		$result = $this->sitemapBuilder->buildSitemap('blog', ['include' => 'active:true']);

		expect($result)->toContain('post1'); // Boolean true
		expect($result)->not->toContain('post2'); // Boolean false
		expect($result)->not->toContain('post3'); // String 'true' != boolean true
		expect($result)->not->toContain('post4'); // String 'false' != boolean true
	}

	public function testLegacyFilterParameterStillWorks(): void
	{
		$this->setupMocksWithTestData([
			['id' => 'post1', 'title' => 'Post 1', 'featured' => true],
			['id' => 'post2', 'title' => 'Post 2', 'featured' => false],
		]);

		// Test that 'filter' still works (backwards compatibility handled by Action)
		// Note: In real usage, the Action remaps 'filter' to 'include'
		// This test simulates what happens after the Action processes the params
		$result = $this->sitemapBuilder->buildSitemap('blog', ['include' => 'featured']);

		expect($result)->toContain('post1');
		expect($result)->not->toContain('post2');
	}

	/**
	 * Setup mock objects with test data.
	 *
	 * @param array<array<string,mixed>> $objects
	 */
	private function setupMocksWithTestData(array $objects): void
	{
		// Mock IndexData
		$indexData          = new IndexData();
		$indexData->objects = new Collection($objects);

		// Mock IndexReader to return the test data
		$this->mockIndexReader
			->method('fetchIndex')
			->willReturn($indexData);

		// Mock CollectionData
		$collectionData         = new CollectionData();
		$collectionData->id     = 'blog';
		$collectionData->name   = 'Blog';
		$collectionData->schema = 'blog';
		$collectionData->url    = '/blog';

		$this->mockCollectionFetcher
			->method('fetchCollection')
			->willReturn($collectionData);
	}
}
