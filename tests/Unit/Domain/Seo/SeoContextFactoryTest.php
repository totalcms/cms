<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Seo;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\SeoContextFactory;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

final class SeoContextFactoryTest extends TestCase
{
	private SeoContextFactory $factory;
	private MockObject $seoSettings;
	private MockObject $collectionFetcher;
	private MockObject $urlBuilder;
	private MockObject $media;

	protected function setUp(): void
	{
		$this->seoSettings       = $this->createMock(SeoSettingsLoader::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->urlBuilder        = $this->createMock(ObjectUrlBuilder::class);
		$this->media             = $this->createMock(MediaTwigAdapter::class);
		$builderConfig           = $this->createMock(BuilderConfigService::class);

		// The loader owns the site-name fallback chain, so what it hands back is
		// already resolved — the factory just reads `siteName`.
		$this->seoSettings->method('load')->willReturn(
			SeoSettings::fromArray(['siteName' => 'Bistro', 'baseUrl' => 'https://example.com'], 'example.com'),
		);
		$builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->urlBuilder->method('hasEmptySegments')->willReturnCallback(
			static fn (string $url): bool => str_contains($url, '//'),
		);
		$this->media->method('imagePath')->willReturn('');

		$this->factory = new SeoContextFactory(
			$this->seoSettings,
			$this->collectionFetcher,
			$this->urlBuilder,
			$this->media,
			$builderConfig,
		);
	}

	/** @param array<string,mixed> $seo */
	private function blogCollection(array $seo = []): CollectionData
	{
		return $this->collection('blog', 'blog', $seo);
	}

	/** @param array<string,mixed> $seo */
	private function collection(string $id, string $schema, array $seo = []): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $schema;
		$collection->url    = '/' . $id;
		$collection->seo    = $seo;

		return $collection;
	}

	public function testNullSubjectIsTheNoneKind(): void
	{
		$ctx = $this->factory->make(null);

		$this->assertSame('none', $ctx->kind);
		$this->assertSame('', $ctx->url);
		$this->assertSame('', $ctx->collectionId);
		$this->assertSame([], $ctx->object);
		$this->assertSame('Bistro', $ctx->siteName);
	}

	public function testBuilderPageResolvesToThePageKind(): void
	{
		$ctx = $this->factory->make([
			'id'       => 'about',
			'route'    => '/about',
			'template' => 'pages/about.twig',
			'seo'      => ['noindex' => true],
		]);

		$this->assertSame('page', $ctx->kind);
		$this->assertSame('https://example.com/about', $ctx->url);
		$this->assertTrue($ctx->fields->noindex);
		$this->assertSame(['type' => '', 'title' => 'title', 'description' => 'description', 'image' => 'image'], $ctx->seoBlock);
	}

	public function testTemplatedPageRouteHasNoCanonicalUrl(): void
	{
		$ctx = $this->factory->make([
			'id'       => 'blog-post',
			'route'    => '/blog/{id}',
			'template' => 'pages/blog-post.twig',
		]);

		$this->assertSame('page', $ctx->kind);
		$this->assertSame('', $ctx->url);
	}

	public function testBlogObjectGetsTheArticleDefaultBlock(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->blogCollection());
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/hello');

		$ctx = $this->factory->make(['id' => 'hello', 'title' => 'Hello'], ['collection' => 'blog']);

		$this->assertSame('object', $ctx->kind);
		$this->assertSame('blog', $ctx->collectionId);
		$this->assertSame(['type' => 'article', 'title' => 'title', 'description' => 'summary', 'image' => 'image'], $ctx->seoBlock);
		$this->assertSame('https://example.com/blog/hello', $ctx->url);
	}

	public function testLegacyBlogObjectGetsTheArticleDefaultBlock(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('archive', 'blog-legacy'));
		$this->urlBuilder->method('buildUrl')->willReturn('/archive/hello');

		$ctx = $this->factory->make(['id' => 'hello'], ['collection' => 'archive']);

		$this->assertSame(['type' => 'article', 'title' => 'title', 'description' => 'summary', 'image' => 'image'], $ctx->seoBlock);
	}

	public function testFeedObjectDescribesItselfFromContent(): void
	{
		// The `feed` schema has no `summary` — `content` is the only prose it carries.
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('news', 'feed'));
		$this->urlBuilder->method('buildUrl')->willReturn('/news/hello');

		$ctx = $this->factory->make(['id' => 'hello'], ['collection' => 'news']);

		$this->assertSame(['type' => 'article', 'title' => 'title', 'description' => 'content', 'image' => 'image'], $ctx->seoBlock);
	}

	public function testPodcastEpisodeIsAWebsiteWithItsArtAsTheImage(): void
	{
		// An episode is a media item, not an article: `website` keeps it out of
		// the Article JSON-LD, and its share image is the episode `art`.
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('episodes', 'podcast-episode'));
		$this->urlBuilder->method('buildUrl')->willReturn('/episodes/hello');

		$ctx = $this->factory->make(['id' => 'hello'], ['collection' => 'episodes']);

		$this->assertSame(['type' => 'website', 'title' => 'title', 'description' => 'summary', 'image' => 'art'], $ctx->seoBlock);
	}

	public function testExplicitCollectionSeoBlockOverridesTheDefault(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(
			$this->blogCollection(['type' => 'website', 'title' => 'name', 'description' => 'excerpt', 'image' => 'cover']),
		);
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/hello');

		$ctx = $this->factory->make(['id' => 'hello'], ['collection' => 'blog']);

		$this->assertSame(['type' => 'website', 'title' => 'name', 'description' => 'excerpt', 'image' => 'cover'], $ctx->seoBlock);
	}

	public function testPartialBlogBlockMergesOverTheArticleDefault(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->blogCollection(['image' => 'cover']));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/hello');

		$ctx = $this->factory->make(['id' => 'hello'], ['collection' => 'blog']);

		// Only `image` was mapped — the other two keep the article default.
		$this->assertSame(['type' => 'article', 'title' => 'title', 'description' => 'summary', 'image' => 'cover'], $ctx->seoBlock);
	}

	public function testMappedTitlePropertyMergesOverTheArticleDefault(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->blogCollection(['title' => 'name']));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/hello');

		$ctx = $this->factory->make(['id' => 'hello'], ['collection' => 'blog']);

		// Only `title` was mapped — the other three keep the article default.
		$this->assertSame(['type' => 'article', 'title' => 'name', 'description' => 'summary', 'image' => 'image'], $ctx->seoBlock);
	}

	public function testPartialBlockOnANonBlogCollectionLeavesTheOtherKeysEmpty(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(
			$this->collection('recipes', 'recipe', ['description' => 'intro']),
		);
		$this->urlBuilder->method('buildUrl')->willReturn('/recipes/pie');

		$ctx = $this->factory->make(['id' => 'pie'], ['collection' => 'recipes']);

		$this->assertSame(['type' => '', 'title' => '', 'description' => 'intro', 'image' => ''], $ctx->seoBlock);
	}

	public function testAbsoluteCollectionUrlIsNotPrefixedTwice(): void
	{
		// A collection whose `url` is already an absolute address (another host,
		// a CDN) builds an absolute object URL — prefixing baseUrl again would
		// produce `https://example.com/https://cdn.example.com/...`.
		$collection      = $this->collection('news', 'blog');
		$collection->url = 'https://cdn.example.com/news';

		$this->collectionFetcher->method('fetchCollection')->willReturn($collection);

		// A dedicated URL builder: the shared mock's hasEmptySegments treats the
		// scheme's own `//` as an empty segment, which would mask the pass-through.
		$urlBuilder = $this->createMock(ObjectUrlBuilder::class);
		$urlBuilder->method('buildUrl')->willReturn('https://cdn.example.com/news/hello');
		$urlBuilder->method('hasEmptySegments')->willReturn(false);

		$builderConfig = $this->createMock(BuilderConfigService::class);
		$builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');

		$factory = new SeoContextFactory(
			$this->seoSettings,
			$this->collectionFetcher,
			$urlBuilder,
			$this->media,
			$builderConfig,
		);

		$ctx = $factory->make(['id' => 'hello'], ['collection' => 'news']);

		$this->assertSame('https://cdn.example.com/news/hello', $ctx->url);
	}

	public function testUnknownCollectionYieldsAnEmptyBlockAndNoUrl(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$ctx = $this->factory->make(['id' => 'hello', '_collection' => 'nope']);

		$this->assertSame('object', $ctx->kind);
		$this->assertSame('nope', $ctx->collectionId);
		$this->assertNull($ctx->collectionMeta);
		$this->assertSame(['type' => '', 'title' => '', 'description' => '', 'image' => ''], $ctx->seoBlock);
		$this->assertSame('', $ctx->url);
	}
}
