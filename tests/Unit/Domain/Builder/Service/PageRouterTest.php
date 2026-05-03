<?php

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

final class PageRouterTest extends TestCase
{
	private PageRouter $router;
	private \PHPUnit\Framework\MockObject\MockObject $builderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $collectionLister;
	private \PHPUnit\Framework\MockObject\MockObject $urlBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;

	protected function setUp(): void
	{
		$this->builderConfig    = $this->createMock(BuilderConfigService::class);
		$this->indexReader      = $this->createMock(IndexReader::class);
		$this->collectionLister = $this->createMock(CollectionLister::class);
		$this->urlBuilder       = $this->createMock(ObjectUrlBuilder::class);
		$this->objectFetcher    = $this->createMock(ObjectFetcher::class);

		// Default normalizeUrlPattern behavior — returns the URL unchanged
		// when it already uses Twig syntax, or converts `{name}` to `{{ name }}`.
		// Tests can override with their own callback if needed.
		$this->urlBuilder->method('normalizeUrlPattern')
			->willReturnCallback(static function (string $url): string {
				if (str_contains($url, '{{')) return $url;
				return (string)preg_replace('/\{(\w+)\}/', '{{ $1 }}', $url);
			});

		$this->router = new PageRouter(
			$this->builderConfig,
			$this->indexReader,
			$this->collectionLister,
			$this->urlBuilder,
			$this->objectFetcher,
		);
	}

	private function setupPagesCollection(array $pages): void
	{
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->builderConfig->method('pagesCollectionExists')->willReturn(true);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($pages));
		$this->collectionLister->method('listAllCollections')->willReturn([]);
	}

	// --- Static Route Matching ---

	public function testMatchesStaticRoute(): void
	{
		$this->setupPagesCollection([
			['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'layout' => 'default'],
		]);

		$match = $this->router->match('/about');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/about.twig', $match->template);
		$this->assertSame('About', $match->pageData['title']);
		$this->assertSame([], $match->params);
		$this->assertNull($match->collection);
	}

	public function testMatchesHomepageRoute(): void
	{
		$this->setupPagesCollection([
			['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index', 'layout' => 'default'],
		]);

		$match = $this->router->match('/');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/index.twig', $match->template);
	}

	public function testReturnsNullForNoMatch(): void
	{
		$this->setupPagesCollection([
			['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'layout' => 'default'],
		]);

		$match = $this->router->match('/contact');

		$this->assertNull($match);
	}

	public function testSkipsDraftPages(): void
	{
		$this->setupPagesCollection([
			['id' => 'draft', 'title' => 'Draft', 'route' => '/draft', 'template' => 'draft', 'layout' => 'default', 'draft' => true],
		]);

		$match = $this->router->match('/draft');

		$this->assertNull($match);
	}

	public function testRoutesNavFalsePages(): void
	{
		$this->setupPagesCollection([
			['id' => 'privacy', 'title' => 'Privacy', 'route' => '/privacy', 'template' => 'privacy', 'layout' => 'default', 'nav' => false],
		]);

		$match = $this->router->match('/privacy');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/privacy.twig', $match->template);
	}

	public function testHydratesDataFromFullObjectOnMatch(): void
	{
		$this->setupPagesCollection([
			['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index', 'layout' => 'default'],
		]);

		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'       => 'home',
			'title'    => 'Home',
			'route'    => '/',
			'template' => 'index',
			'data'     => '{"hero":"Welcome","cta":"Sign up"}',
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($mockObject);

		$match = $this->router->match('/');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame(['hero' => 'Welcome', 'cta' => 'Sign up'], $match->pageData['data']);
	}

	// --- Collection URL syntax variants ---

	public function testCollectionUrlAcceptsSlimStylePlaceholders(): void
	{
		// `/blog/{id}` (Slim-style) should behave the same as `/blog/{{ id }}`
		// (Twig-style) and as bare `/blog`. Without normalization, the router
		// would treat `{id}` as a literal URL segment.
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->builderConfig->method('pagesCollectionExists')->willReturn(true);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([]));

		$collection            = new CollectionData();
		$collection->id        = 'blog';
		$collection->url       = '/blog/{id}';
		$collection->prettyUrl = true;
		$this->collectionLister->method('listAllCollections')->willReturn([$collection]);

		// urlBuilder is a mock — stub the bits the template-URL path uses to
		// behave like the real implementation (normalizeUrlPattern is stubbed
		// in setUp() so every test gets the same default).
		$this->urlBuilder->method('isTemplateUrl')
			->willReturnCallback(static fn (string $url): bool => str_contains($url, '{{'));
		$this->urlBuilder->method('extractTemplateFields')
			->willReturnCallback(static function (string $url): array {
				preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $url, $m);

				return array_values(array_unique($m[1]));
			});

		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'    => 'hello',
			'title' => 'Hello',
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($mockObject);

		$match = $this->router->match('/blog/hello');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('blog', $match->collection);
		$this->assertSame(['id' => 'hello'], $match->params);
	}

	// --- Universal 404 fallback ---

	public function testFallback404ReturnsPageWithStatus404(): void
	{
		$this->setupPagesCollection([
			['id' => 'home',     'title' => 'Home', 'route' => '/',    'template' => 'index'],
			['id' => 'not-found', 'title' => '404', 'route' => '/404', 'template' => 'not-found', 'status' => 404],
		]);

		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'       => 'not-found',
			'title'    => '404',
			'route'    => '/404',
			'template' => 'not-found',
			'status'   => 404,
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($mockObject);

		$match = $this->router->fallback404();

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/not-found.twig', $match->template);
		$this->assertSame(404, $match->status);
	}

	public function testFallback404ReturnsNullWhenNoPageHasStatus404(): void
	{
		$this->setupPagesCollection([
			['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index'],
		]);

		$this->assertNull($this->router->fallback404());
	}

	public function testFallback404SkipsDraftPages(): void
	{
		$this->setupPagesCollection([
			['id' => 'draft-404', 'title' => '404', 'route' => '/404', 'template' => 'not-found', 'status' => 404, 'draft' => true],
		]);

		$this->assertNull($this->router->fallback404());
	}

	// --- Catch-all routes ---

	public function testCatchAllMatchesMultiSegmentPath(): void
	{
		$this->setupPagesCollection([
			['id' => 'docs', 'title' => 'Docs', 'route' => '/docs/{slug:.*}', 'template' => 'docs', 'layout' => 'default'],
		]);

		$match = $this->router->match('/docs/api/v1/intro');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame(['slug' => 'api/v1/intro'], $match->params);
	}

	public function testCatchAllAtRootMatchesEverything(): void
	{
		$this->setupPagesCollection([
			['id' => 'fallback', 'title' => 'Fallback', 'route' => '/{slug:.*}', 'template' => 'fallback', 'layout' => 'default'],
		]);

		$match = $this->router->match('/anything/here');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame(['slug' => 'anything/here'], $match->params);
	}

	public function testSpecificRouteWinsOverCatchAll(): void
	{
		$this->setupPagesCollection([
			['id' => 'fallback',  'title' => 'Fallback',  'route' => '/{slug:.*}', 'template' => 'fallback',  'layout' => 'default'],
			['id' => 'blog-post', 'title' => 'Blog Post', 'route' => '/blog/{id}', 'template' => 'blog-post', 'layout' => 'default'],
		]);

		$match = $this->router->match('/blog/hello');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/blog-post.twig', $match->template);
		$this->assertSame(['id' => 'hello'], $match->params);
	}

	public function testStaticRouteWinsOverCatchAll(): void
	{
		$this->setupPagesCollection([
			['id' => 'fallback', 'title' => 'Fallback', 'route' => '/{slug:.*}', 'template' => 'fallback', 'layout' => 'default'],
			['id' => 'about',    'title' => 'About',    'route' => '/about',     'template' => 'about',    'layout' => 'default'],
		]);

		$match = $this->router->match('/about');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/about.twig', $match->template);
	}

	public function testStatusFlowsThroughToRouteMatch(): void
	{
		$this->setupPagesCollection([
			['id' => 'gone', 'title' => 'Gone', 'route' => '/gone', 'template' => 'gone', 'layout' => 'default', 'status' => 410],
		]);

		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'       => 'gone',
			'title'    => 'Gone',
			'route'    => '/gone',
			'template' => 'gone',
			'status'   => 410,
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($mockObject);

		$match = $this->router->match('/gone');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame(410, $match->status);
	}

	public function testStatusDefaultsTo200WhenAbsent(): void
	{
		$this->setupPagesCollection([
			['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'layout' => 'default'],
		]);

		$match = $this->router->match('/about');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame(200, $match->status);
	}

	public function testFallsBackToIndexPageWhenObjectFetchFails(): void
	{
		$this->setupPagesCollection([
			['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'layout' => 'default'],
		]);

		$this->objectFetcher->method('fetchObject')
			->willThrowException(new \UnexpectedValueException('not found'));

		$match = $this->router->match('/about');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('About', $match->pageData['title']);
		$this->assertSame([], $match->pageData['data']);
	}

	public function testSkipsPagesWithEmptyRoute(): void
	{
		$this->setupPagesCollection([
			['id' => 'noroute', 'title' => 'No Route', 'route' => '', 'template' => 'noroute', 'layout' => 'default'],
		]);

		$match = $this->router->match('/noroute');

		$this->assertNull($match);
	}

	public function testSkipsPagesWithEmptyTemplate(): void
	{
		$this->setupPagesCollection([
			['id' => 'notemplate', 'title' => 'No Template', 'route' => '/notemplate', 'template' => '', 'layout' => 'default'],
		]);

		$match = $this->router->match('/notemplate');

		$this->assertNull($match);
	}

	// --- Dynamic Route Matching ---

	public function testMatchesDynamicRoute(): void
	{
		$this->setupPagesCollection([
			['id' => 'product', 'title' => 'Product', 'route' => '/products/{id}', 'template' => 'product', 'layout' => 'default'],
		]);

		$match = $this->router->match('/products/widget-x');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/product.twig', $match->template);
		$this->assertSame(['id' => 'widget-x'], $match->params);
	}

	public function testMatchesDynamicRouteWithMultipleParams(): void
	{
		$this->setupPagesCollection([
			['id' => 'blog-post', 'title' => 'Blog Post', 'route' => '/blog/{category}/{slug}', 'template' => 'post', 'layout' => 'default'],
		]);

		$match = $this->router->match('/blog/tech/my-first-post');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame(['category' => 'tech', 'slug' => 'my-first-post'], $match->params);
	}

	public function testDynamicRouteDoesNotMatchWrongSegmentCount(): void
	{
		$this->setupPagesCollection([
			['id' => 'product', 'title' => 'Product', 'route' => '/products/{id}', 'template' => 'product', 'layout' => 'default'],
		]);

		$match = $this->router->match('/products/category/widget-x');

		$this->assertNull($match);
	}

	// --- Route Priority ---

	public function testStaticRouteBeforeDynamic(): void
	{
		$this->setupPagesCollection([
			['id' => 'product-detail', 'title' => 'Product Detail', 'route' => '/products/{id}', 'template' => 'product-detail', 'layout' => 'default'],
			['id' => 'products', 'title' => 'Products', 'route' => '/products', 'template' => 'products-index', 'layout' => 'default'],
		]);

		$match = $this->router->match('/products');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/products-index.twig', $match->template);
		$this->assertSame([], $match->params);
	}

	public function testLongerDynamicRouteMatchesFirst(): void
	{
		$this->setupPagesCollection([
			['id' => 'product', 'title' => 'Product', 'route' => '/products/{id}', 'template' => 'product', 'layout' => 'default'],
			['id' => 'product-review', 'title' => 'Review', 'route' => '/products/{id}/reviews', 'template' => 'review', 'layout' => 'default'],
		]);

		$match = $this->router->match('/products/widget-x/reviews');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/review.twig', $match->template);
	}

	// --- Path Normalization ---

	public function testStripsTrailingSlash(): void
	{
		$this->setupPagesCollection([
			['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'layout' => 'default'],
		]);

		$match = $this->router->match('/about/');

		$this->assertInstanceOf(RouteMatch::class, $match);
	}

	public function testStripsQueryString(): void
	{
		$this->setupPagesCollection([
			['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about', 'layout' => 'default'],
		]);

		$match = $this->router->match('/about?ref=nav');

		$this->assertInstanceOf(RouteMatch::class, $match);
	}

	// --- No Pages Collection ---

	public function testReturnsNullWhenNoPagesCollection(): void
	{
		$this->builderConfig->method('pagesCollectionExists')->willReturn(false);
		$this->collectionLister->method('listAllCollections')->willReturn([]);

		$match = $this->router->match('/about');

		$this->assertNull($match);
	}

	// --- Collection URL Matching ---

	public function testMatchesSimpleCollectionUrl(): void
	{
		$this->builderConfig->method('pagesCollectionExists')->willReturn(false);

		$collection            = new CollectionData();
		$collection->id        = 'blog';
		$collection->schema    = 'blog';
		$collection->url       = '/blog';
		$collection->prettyUrl = true;

		$this->collectionLister->method('listAllCollections')->willReturn([$collection]);
		$this->urlBuilder->method('isTemplateUrl')->willReturn(false);

		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn(['id' => 'my-post', 'title' => 'My Post']);
		$this->objectFetcher->method('fetchObject')->with('blog', 'my-post')->willReturn($object);

		$match = $this->router->match('/blog/my-post');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/blog.twig', $match->template);
		$this->assertSame('blog', $match->collection);
	}

	public function testSkipsNonPrettyUrlCollections(): void
	{
		$this->builderConfig->method('pagesCollectionExists')->willReturn(false);

		$collection            = new CollectionData();
		$collection->id        = 'blog';
		$collection->schema    = 'blog';
		$collection->url       = '/blog';
		$collection->prettyUrl = false;

		$this->collectionLister->method('listAllCollections')->willReturn([$collection]);

		$match = $this->router->match('/blog/my-post');

		$this->assertNull($match);
	}

	public function testSkipsCollectionsWithNoUrl(): void
	{
		$this->builderConfig->method('pagesCollectionExists')->willReturn(false);

		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';
		$collection->url    = '';

		$this->collectionLister->method('listAllCollections')->willReturn([$collection]);

		$match = $this->router->match('/blog/my-post');

		$this->assertNull($match);
	}

	public function testCollectionMatchReturnsNullForMissingObject(): void
	{
		$this->builderConfig->method('pagesCollectionExists')->willReturn(false);

		$collection            = new CollectionData();
		$collection->id        = 'blog';
		$collection->schema    = 'blog';
		$collection->url       = '/blog';
		$collection->prettyUrl = true;

		$this->collectionLister->method('listAllCollections')->willReturn([$collection]);
		$this->urlBuilder->method('isTemplateUrl')->willReturn(false);
		$this->objectFetcher->method('fetchObject')->willThrowException(new \DomainException('Not found'));

		$match = $this->router->match('/blog/nonexistent');

		$this->assertNull($match);
	}

	// --- Builder Pages Take Priority Over Collections ---

	public function testBuilderPageMatchesBeforeCollection(): void
	{
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->builderConfig->method('pagesCollectionExists')->willReturn(true);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'blog', 'title' => 'Blog', 'route' => '/blog', 'template' => 'blog-index', 'layout' => 'default'],
		]));

		// Collection also has /blog URL — should not be checked
		$collection            = new CollectionData();
		$collection->id        = 'blog';
		$collection->schema    = 'blog';
		$collection->url       = '/blog';
		$collection->prettyUrl = true;

		$this->collectionLister->method('listAllCollections')->willReturn([$collection]);

		$match = $this->router->match('/blog');

		$this->assertInstanceOf(RouteMatch::class, $match);
		$this->assertSame('pages/blog-index.twig', $match->template);
		$this->assertNull($match->collection);
	}
}
