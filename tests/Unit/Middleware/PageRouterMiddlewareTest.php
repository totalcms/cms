<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Middleware\PageRouterMiddleware;

final class PageRouterMiddlewareTest extends TestCase
{
	private PageRouterMiddleware $middleware;
	private \PHPUnit\Framework\MockObject\MockObject $pageRouter;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;

	protected function setUp(): void
	{
		$this->pageRouter = $this->createMock(PageRouter::class);
		$this->twigEngine = $this->createMock(TwigEngine::class);

		$this->middleware = new PageRouterMiddleware(
			$this->pageRouter,
			$this->twigEngine,
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
			layout: 'default',
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

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testReturnsOriginal404WhenRenderFails(): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/about');
		$handler = $this->createHandler(404);

		$match = new RouteMatch(
			template: 'pages/about.twig',
			layout: 'default',
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
			layout: 'default',
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
			layout: 'default',
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
