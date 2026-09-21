<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sitemap\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Query\Service\ObjectFilter;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;
use TotalCMS\Domain\Sitemap\Service\SitemapUrlResolver;

/**
 * The resolver applies the sitemap's per-object rules to one record. Each
 * rule SitemapBuilder / PageSitemapBuilder enforce over a list is pinned
 * here for a single object, so the two can never disagree about whether a
 * URL is public. Real IndexFilter + ObjectFilter, as the builder tests do.
 */
final class SitemapUrlResolverTest extends TestCase
{
	private SitemapUrlResolver $resolver;
	private MockObject $collections;
	private MockObject $urls;
	private MockObject $builderConfig;

	protected function setUp(): void
	{
		$this->collections   = $this->createMock(CollectionFetcher::class);
		$this->urls          = $this->createMock(ObjectUrlBuilder::class);
		$this->builderConfig = $this->createMock(BuilderConfigService::class);
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');

		$seo = $this->createMock(SeoSettingsLoader::class);
		$seo->method('load')->willReturn(SeoSettings::fromArray(['baseUrl' => 'https://example.com'], 'example.com'));

		$this->urls->method('buildUrl')->willReturnCallback(fn (CollectionData $c, array $o): string => '/blog/' . $o['id']);
		$this->urls->method('hasEmptySegments')->willReturn(false);

		$this->resolver = new SitemapUrlResolver(
			$this->collections,
			$this->urls,
			new IndexFilter($this->createMock(IndexReader::class), new ObjectFilter()),
			$seo,
			$this->builderConfig,
		);
	}

	/** @param array<string,mixed> $sitemap */
	private function blog(array $sitemap = ['enabled' => true]): void
	{
		$c          = new CollectionData();
		$c->id      = 'blog';
		$c->sitemap = $sitemap;
		$this->collections->method('fetchCollection')->with('blog')->willReturn($c);
	}

	public function testAnIncludedObjectResolvesToItsAbsoluteUrl(): void
	{
		$this->blog();

		$this->assertSame('https://example.com/blog/hello', $this->resolver->urlFor('blog', ['id' => 'hello']));
	}

	public function testACollectionWithItsSitemapOffResolvesNothing(): void
	{
		$this->blog(['enabled' => false]);

		$this->assertNull($this->resolver->urlFor('blog', ['id' => 'hello']));
	}

	public function testAMissingCollectionResolvesNothing(): void
	{
		$this->collections->method('fetchCollection')->willReturn(null);

		$this->assertNull($this->resolver->urlFor('nope', ['id' => 'hello']));
	}

	public function testTheSitemapsExcludeFilterKeepsDraftsOut(): void
	{
		// The sitemap card's own exclude rule is the draft rule — same as the builder.
		$this->blog(['enabled' => true, 'exclude' => 'draft:true']);

		$this->assertNull($this->resolver->urlFor('blog', ['id' => 'hello', 'draft' => true]));
		$this->assertSame('https://example.com/blog/hello', $this->resolver->urlFor('blog', ['id' => 'hello', 'draft' => false]));
	}

	public function testNoindexOnTheSeoCardResolvesNothing(): void
	{
		$this->blog();

		$this->assertNull($this->resolver->urlFor('blog', ['id' => 'hello', 'seo' => ['noindex' => true]]));
	}

	public function testAUrlWithAnEmptySegmentResolvesNothing(): void
	{
		$this->blog();
		$urls = $this->createMock(ObjectUrlBuilder::class);
		$urls->method('buildUrl')->willReturn('/blog//');
		$urls->method('hasEmptySegments')->willReturn(true);
		$seo = $this->createMock(SeoSettingsLoader::class);
		$seo->method('load')->willReturn(SeoSettings::fromArray([], 'example.com'));
		$resolver = new SitemapUrlResolver($this->collections, $urls, new IndexFilter($this->createMock(IndexReader::class), new ObjectFilter()), $seo, $this->builderConfig);

		$this->assertNull($resolver->urlFor('blog', ['id' => 'hello']));
	}

	public function testAPublishedStaticBuilderPageResolvesToItsRoute(): void
	{
		$page = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'page', 'draft' => false, 'sitemap' => true];

		$this->assertSame('https://example.com/about', $this->resolver->urlFor('builder-pages', $page));
	}

	public function testDraftOptedOutAndDynamicBuilderPagesResolveNothing(): void
	{
		$base = ['id' => 'p', 'title' => 'P', 'template' => 'page', 'draft' => false, 'sitemap' => true, 'route' => '/p'];

		$this->assertNull($this->resolver->urlFor('builder-pages', ['draft' => true] + $base));
		$this->assertNull($this->resolver->urlFor('builder-pages', ['sitemap' => false] + $base));
		$this->assertNull($this->resolver->urlFor('builder-pages', ['route' => '/blog/{id}'] + $base));
		$this->assertNull($this->resolver->urlFor('builder-pages', ['seo' => ['noindex' => true]] + $base));
	}
}
