<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sitemap\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Sitemap\Service\PageSitemapBuilder;
use TotalCMS\Support\Config;

/**
 * Tests for the page sitemap builder.
 *
 * Verifies that draft, hidden-from-sitemap, empty-route, and dynamic-route
 * pages are excluded, and that per-page frequency/priority/lastmod are emitted.
 */
final class PageSitemapBuilderTest extends TestCase
{
	private PageSitemapBuilder $builder;
	private \PHPUnit\Framework\MockObject\MockObject $mockBuilderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $mockIndexReader;
	private Config $config;

	protected function setUp(): void
	{
		$this->mockBuilderConfig = $this->createMock(BuilderConfigService::class);
		$this->mockIndexReader   = $this->createMock(IndexReader::class);
		$this->config            = $this->createMock(Config::class);

		$this->config->domain = 'example.com';

		$this->mockBuilderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->mockBuilderConfig->method('pagesCollectionExists')->willReturn(true);

		$this->builder = new PageSitemapBuilder(
			$this->mockBuilderConfig,
			$this->mockIndexReader,
			$this->config,
		);
	}

	public function testReturnsEmptySitemapWhenPagesCollectionMissing(): void
	{
		$mockBuilderConfig = $this->createMock(BuilderConfigService::class);
		$mockBuilderConfig->method('pagesCollectionExists')->willReturn(false);
		$mockBuilderConfig->method('getPagesCollectionId')->willReturn('builder-pages');

		$builder = new PageSitemapBuilder($mockBuilderConfig, $this->mockIndexReader, $this->config);

		$xml = $builder->buildSitemap();

		expect($xml)->toContain('<urlset');
		expect($xml)->not->toContain('<url>');
	}

	public function testIncludesPublishedStaticPages(): void
	{
		$this->setIndexObjects([
			['id' => 'home',    'route' => '/',        'draft' => false, 'sitemap' => true],
			['id' => 'about',   'route' => '/about',   'draft' => false, 'sitemap' => true],
			['id' => 'contact', 'route' => '/contact', 'draft' => false, 'sitemap' => true],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('https://example.com/');
		expect($xml)->toContain('https://example.com/about');
		expect($xml)->toContain('https://example.com/contact');
	}

	public function testExcludesDrafts(): void
	{
		$this->setIndexObjects([
			['id' => 'live',  'route' => '/live',  'draft' => false, 'sitemap' => true],
			['id' => 'draft', 'route' => '/draft', 'draft' => true,  'sitemap' => true],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('https://example.com/live');
		expect($xml)->not->toContain('/draft');
	}

	public function testExcludesPagesWithSitemapFalse(): void
	{
		$this->setIndexObjects([
			['id' => 'public',  'route' => '/public',  'draft' => false, 'sitemap' => true],
			['id' => 'private', 'route' => '/private', 'draft' => false, 'sitemap' => false],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('https://example.com/public');
		expect($xml)->not->toContain('/private');
	}

	public function testExcludesEmptyRoutes(): void
	{
		$this->setIndexObjects([
			['id' => 'valid',   'route' => '/valid', 'draft' => false, 'sitemap' => true],
			['id' => 'noroute', 'route' => '',       'draft' => false, 'sitemap' => true],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('https://example.com/valid');
		expect($xml)->not->toContain('noroute');
	}

	public function testExcludesDynamicRoutes(): void
	{
		$this->setIndexObjects([
			['id' => 'static',  'route' => '/about',      'draft' => false, 'sitemap' => true],
			['id' => 'dynamic', 'route' => '/blog/{id}',  'draft' => false, 'sitemap' => true],
			['id' => 'curly',   'route' => '/x/{slug}/y', 'draft' => false, 'sitemap' => true],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('https://example.com/about');
		expect($xml)->not->toContain('{id}');
		expect($xml)->not->toContain('{slug}');
	}

	public function testEmitsLastModFromUpdatedField(): void
	{
		$this->setIndexObjects([
			[
				'id'      => 'home',
				'route'   => '/',
				'draft'   => false,
				'sitemap' => true,
				'updated' => '2026-04-28T17:14:05-07:00',
			],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('<lastmod>');
		expect($xml)->toContain('2026-04-28');
	}

	public function testEmitsChangeFrequencyWhenSet(): void
	{
		$this->setIndexObjects([
			[
				'id'              => 'home',
				'route'           => '/',
				'draft'           => false,
				'sitemap'         => true,
				'changeFrequency' => 'weekly',
			],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('<changefreq>weekly</changefreq>');
	}

	public function testOmitsChangeFrequencyWhenEmpty(): void
	{
		$this->setIndexObjects([
			[
				'id'              => 'home',
				'route'           => '/',
				'draft'           => false,
				'sitemap'         => true,
				'changeFrequency' => '',
			],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->not->toContain('<changefreq>');
	}

	public function testEmitsPriorityWhenGreaterThanZero(): void
	{
		$this->setIndexObjects([
			[
				'id'       => 'home',
				'route'    => '/',
				'draft'    => false,
				'sitemap'  => true,
				'priority' => 0.8,
			],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('<priority>0.8</priority>');
	}

	public function testOmitsPriorityWhenZero(): void
	{
		$this->setIndexObjects([
			[
				'id'       => 'home',
				'route'    => '/',
				'draft'    => false,
				'sitemap'  => true,
				'priority' => 0,
			],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->not->toContain('<priority>');
	}

	public function testQueryParamFrequencyAppliedAsDefault(): void
	{
		$this->setIndexObjects([
			['id' => 'home', 'route' => '/', 'draft' => false, 'sitemap' => true],
		]);

		$xml = $this->builder->buildSitemap(['frequency' => 'monthly']);

		expect($xml)->toContain('<changefreq>monthly</changefreq>');
	}

	public function testPerPageFrequencyOverridesQueryParam(): void
	{
		$this->setIndexObjects([
			[
				'id'              => 'home',
				'route'           => '/',
				'draft'           => false,
				'sitemap'         => true,
				'changeFrequency' => 'daily',
			],
		]);

		$xml = $this->builder->buildSitemap(['frequency' => 'monthly']);

		expect($xml)->toContain('<changefreq>daily</changefreq>');
		expect($xml)->not->toContain('monthly');
	}

	public function testDefaultsSitemapToTrueWhenFieldMissing(): void
	{
		// Older pages saved before the `sitemap` field existed should still be included.
		$this->setIndexObjects([
			['id' => 'legacy', 'route' => '/legacy', 'draft' => false],
		]);

		$xml = $this->builder->buildSitemap();

		expect($xml)->toContain('https://example.com/legacy');
	}

	/**
	 * @param array<array<string,mixed>> $objects
	 */
	private function setIndexObjects(array $objects): void
	{
		$indexData          = new IndexData();
		$indexData->objects = new Collection($objects);

		$this->mockIndexReader->method('fetchIndex')->willReturn($indexData);
	}
}
