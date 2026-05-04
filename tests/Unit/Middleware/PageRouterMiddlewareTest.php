<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRunner;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Middleware\PageRouterMiddleware;

final class PageRouterMiddlewareTest extends TestCase
{
	private PageRouterMiddleware $middleware;
	private \PHPUnit\Framework\MockObject\MockObject $pageRouter;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $pageMiddlewareRunner;

	protected function setUp(): void
	{
		$this->pageRouter           = $this->createMock(PageRouter::class);
		$this->twigEngine           = $this->createMock(TwigEngine::class);
		$this->pageMiddlewareRunner = $this->createMock(PageMiddlewareRunner::class);

		// Default: middleware chain is empty / passes through. Individual
		// tests that care about per-page middleware behavior override this.
		$this->pageMiddlewareRunner->method('run')->willReturn(null);

		$this->middleware = new PageRouterMiddleware(
			$this->pageRouter,
			$this->twigEngine,
			$this->pageMiddlewareRunner,
		);
	}

	private function createHandler(int $statusCode = 200, string $body = ''): RequestHandlerInterface
	{
		$handler  = $this->createMock(RequestHandlerInterface::class);
		$response = new Response();

		if ($body !== '') {
			$response->getBody()->write($body);
		}

		$handler->method('handle')->willReturn($response->withStatus($statusCode));

		return $handler;
	}

	public function testPassesThroughWhenSlimMatchesRoute(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/api/collections/blog');
		$handler = $this->createHandler(200, '{"collections":[]}');

		$this->pageRouter->expects($this->never())->method('match');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testPassesThroughForNonGetRequests(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/about');
		$handler = $this->createHandler(404);

		$this->pageRouter->expects($this->never())->method('match');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testInterceptsGet404AndMatchesBuilderPage(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/about.twig',
			pageData: ['id' => 'about', 'title' => 'About Us'],
			params: [],
		);

		$this->pageRouter->method('match')->with('/about')->willReturn($match);
		$this->twigEngine->method('render')->willReturn('<html><body>About Us</body></html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
		$this->assertStringContainsString('About Us', (string)$response->getBody());
	}

	public function testReturns404WhenNoBuilderPageMatches(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/nonexistent');
		$handler = $this->createHandler(404, '{"error":"Not Found"}');

		$this->pageRouter->method('match')->willReturn(null);
		$this->pageRouter->method('fallback404')->willReturn(null);

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testFallsBackToConfigured404PageWhenNoRouteMatches(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/totally-missing');
		$handler = $this->createHandler(404);

		$this->pageRouter->method('match')->willReturn(null);
		$this->pageRouter->method('fallback404')->willReturn(new RouteMatch(
			template: 'pages/not-found.twig',
			pageData: ['id' => 'not-found', 'title' => '404'],
			params: [],
			status: 404,
		));
		$this->twigEngine->method('render')->willReturn('<html>Custom 404</html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(404, $response->getStatusCode());
		$this->assertStringContainsString('Custom 404', (string)$response->getBody());
	}

	public function testHeadlessModeViaQueryParamReturnsJson(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about?_format=json');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/about.twig',
			pageData: ['id' => 'about', 'title' => 'About'],
			params: [],
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->expects($this->never())->method('render');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

		$payload = json_decode((string)$response->getBody(), true);
		$this->assertSame(['id' => 'about', 'title' => 'About'], $payload['page']);
		$this->assertSame('pages/about.twig', $payload['template']);
	}

	public function testHeadlessModeViaAcceptHeaderReturnsJson(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about')
			->withHeader('Accept', 'application/json');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/about.twig',
			pageData: ['id' => 'about', 'title' => 'About'],
			params: ['lang' => 'en'],
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->expects($this->never())->method('render');

		$response = $this->middleware->process($request, $handler);

		$payload = json_decode((string)$response->getBody(), true);
		$this->assertSame(['lang' => 'en'], $payload['params']);
	}

	public function testHtmlAcceptHeaderDoesNotTriggerHeadlessMode(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about')
			->withHeader('Accept', 'text/html, */*');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/about.twig',
			pageData: ['id' => 'about'],
			params: [],
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->method('render')->willReturn('<html>About</html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
	}

	public function testHeadlessModePreservesCustomStatus(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/gone?_format=json');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/gone.twig',
			pageData: ['id' => 'gone'],
			params: [],
			status: 410,
		);

		$this->pageRouter->method('match')->willReturn($match);

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(410, $response->getStatusCode());
	}

	public function testRedirectsWhen301WithLocationSet(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/old');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/old.twig',
			pageData: ['id' => 'old'],
			params: [],
			status: 301,
			redirectTo: '/new',
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->expects($this->never())->method('render');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(301, $response->getStatusCode());
		$this->assertSame('/new', $response->getHeaderLine('Location'));
		$this->assertSame('', (string)$response->getBody());
	}

	public function testRedirectsWhen302WithAbsoluteUrl(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/temp-redirect');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/temp.twig',
			pageData: ['id' => 'temp'],
			params: [],
			status: 302,
			redirectTo: 'https://example.com/landing',
		);

		$this->pageRouter->method('match')->willReturn($match);

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('https://example.com/landing', $response->getHeaderLine('Location'));
	}

	public function testRendersNormallyWhen3xxButNoRedirectTo(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/old');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/old.twig',
			pageData: ['id' => 'old'],
			params: [],
			status: 301,
			redirectTo: '',
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->method('render')->willReturn('<html>old</html>');

		$response = $this->middleware->process($request, $handler);

		// No Location header — falls through to normal render with 301 status.
		// This is the "broken" pre-redirectTo behavior, kept as the fallback.
		$this->assertSame(301, $response->getStatusCode());
		$this->assertSame('', $response->getHeaderLine('Location'));
	}

	public function testIgnoresRedirectToWhenStatusIs2xx(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/page');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/page.twig',
			pageData: ['id' => 'page'],
			params: [],
			status: 200,
			redirectTo: '/should-not-redirect',
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->method('render')->willReturn('<html>page</html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('', $response->getHeaderLine('Location'));
	}

	public function testAppliesPageStatusToResponse(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/gone');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/gone.twig',
			pageData: ['id' => 'gone', 'title' => 'Gone'],
			params: [],
			status: 410,
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->method('render')->willReturn('<html>Gone</html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(410, $response->getStatusCode());
	}

	public function testReturnsOriginal404WhenRenderFails(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/about.twig',
			pageData: ['id' => 'about', 'title' => 'About'],
			params: [],
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->method('render')->willThrowException(new \RuntimeException('Template not found'));

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testPassesPageDataToTemplate(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/products/widget');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/product.twig',
			pageData: ['id' => 'product', 'title' => 'Product'],
			params: ['id' => 'widget'],
		);

		$this->pageRouter->method('match')->willReturn($match);

		$this->twigEngine->expects($this->once())
			->method('render')
			->with('pages/product.twig', [
				'page'   => ['id' => 'product', 'title' => 'Product'],
				'params' => ['id' => 'widget'],
			])
			->willReturn('<html>Product</html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testCollectionMatchExposesObjectVariableNotPage(): void
	{
		// Collection-URL matches render with `object.*` instead of `page.*` —
		// the loaded record is a collection object, not a page record.
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/blog/hello');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/blog.twig',
			pageData: ['id' => 'hello', 'title' => 'Hello'],
			params: ['id' => 'hello'],
			collection: 'blog',
		);

		$this->pageRouter->method('match')->willReturn($match);

		$this->twigEngine->expects($this->once())
			->method('render')
			->with('pages/blog.twig', [
				'params' => ['id' => 'hello'],
				'object' => ['id' => 'hello', 'title' => 'Hello'],
			])
			->willReturn('<html>Hello</html>');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testHeadlessModeForCollectionMatchUsesObjectField(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/blog/hello?_format=json');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/blog.twig',
			pageData: ['id' => 'hello', 'title' => 'Hello'],
			params: ['id' => 'hello'],
			collection: 'blog',
		);

		$this->pageRouter->method('match')->willReturn($match);

		$response = $this->middleware->process($request, $handler);
		$payload  = json_decode((string)$response->getBody(), true);

		$this->assertArrayHasKey('object', $payload);
		$this->assertArrayNotHasKey('page', $payload);
		$this->assertSame('hello', $payload['object']['id']);
		$this->assertSame('blog', $payload['collection']);
	}

	public function testPassesThroughFor500Errors(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about');
		$handler = $this->createHandler(500, 'Internal Server Error');

		$this->pageRouter->expects($this->never())->method('match');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(500, $response->getStatusCode());
	}

	/**
	 * Content-Type is auto-detected from the route's file extension. A page
	 * routed at `/robots.txt` serves as text/plain so the rendered output is
	 * treated as a plain-text file by browsers and crawlers.
	 *
	 * @dataProvider contentTypeByExtensionProvider
	 */
	public function testContentTypeAutoDetectedFromRouteExtension(string $route, string $expectedContentTypeFragment): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', $route);
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/file.twig',
			pageData: ['id' => 'file', 'title' => 'File'],
			params: [],
		);

		$this->pageRouter->method('match')->willReturn($match);
		$this->twigEngine->method('render')->willReturn('rendered');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString($expectedContentTypeFragment, $response->getHeaderLine('Content-Type'));
	}

	/** @return array<string,array{string,string}> */
	public static function contentTypeByExtensionProvider(): array
	{
		return [
			'plain text (robots.txt)'            => ['/robots.txt', 'text/plain'],
			'plain text (llms.txt)'              => ['/llms.txt', 'text/plain'],
			'XML (sitemap.xml)'                  => ['/sitemap.xml', 'application/xml'],
			'JSON'                               => ['/manifest.json', 'application/json'],
			'CSS'                                => ['/style.css', 'text/css'],
			'JavaScript'                         => ['/script.js', 'application/javascript'],
			'Markdown'                           => ['/notes.md', 'text/markdown'],
			'unknown extension defaults to HTML' => ['/about', 'text/html'],
			'no extension defaults to HTML'      => ['/blog/post', 'text/html'],
		];
	}
}
