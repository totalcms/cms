<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\Route;
use Slim\Routing\RoutingResults;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Action\Admin\Utils\UtilsPageData;
use TotalCMS\Action\Admin\Utils\UtilsPageDataResolver;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\TwigRenderer;

final class AdminUtilsActionTest extends TestCase
{
	private MockObject $renderer;
	private MockObject $editionFeatures;
	private MockObject $request;
	private MockObject $response;
	private MockObject $syncBuilder;

	protected function setUp(): void
	{
		$this->renderer        = $this->createMock(TwigRenderer::class);
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);
		$this->syncBuilder     = $this->createMock(UtilsPageData::class);
		$this->editionFeatures->method('can')->willReturn(true);
	}

	private function action(): AdminUtilsAction
	{
		return new AdminUtilsAction(
			$this->renderer,
			$this->editionFeatures,
			new UtilsPageDataResolver(['sync' => $this->syncBuilder]),
		);
	}

	private function givenRequest(string $path, string $method = 'GET', array $query = []): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn($path);
		$uri->method('getQuery')->willReturn('');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn($method);
		$this->request->method('getQueryParams')->willReturn($query);
	}

	/**
	 * Set up routing context attributes on the mocked request.
	 */
	private function setupRoutingContext(?string $routeName = null): void
	{
		$routeParser    = $this->createMock(RouteParserInterface::class);
		$routingResults = $this->createMock(RoutingResults::class);
		$route          = $routeName !== null
			? $this->createMock(Route::class)
			: null;

		if ($route instanceof MockObject) {
			$route->method('getName')->willReturn($routeName);
		}

		$this->request->method('getAttribute')
			->willReturnCallback(fn (string $name): MockObject|string|null => match ($name) {
				'__routeParser__'    => $routeParser,
				'__routingResults__' => $routingResults,
				'__basePath__'       => '',
				'__route__'          => $route,
				default              => null,
			});
	}

	public function testRendersUtilsTemplateWithDefaultPage(): void
	{
		$this->setupRoutingContext();
		$this->givenRequest('/admin/utils');
		$expected = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'admin/utils.twig', $this->callback(
				fn (array $data): bool => $data['page'] === 'index' && $data['url']['page'] === 'utils' && $data['syncData'] === null,
			))
			->willReturn($expected);

		$this->assertSame($expected, ($this->action())($this->request, $this->response, []));
	}

	public function testNamedRoutesForceTheirPage(): void
	{
		$this->setupRoutingContext('admin-utils-api-keys');
		$this->givenRequest('/admin/utils/api-keys');
		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'admin/utils.twig', $this->callback(
				fn (array $data): bool => $data['page'] === 'api-keys' && $data['url']['params']['page'] === 'api-keys',
			))
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action())($this->request, $this->response, []);
	}

	public function testMergesTheBuildersVariablesOverTheDefaults(): void
	{
		$this->setupRoutingContext();
		$this->givenRequest('/admin/utils/sync');
		$this->syncBuilder->expects($this->once())->method('build')
			->with($this->request, 'sync', '')
			->willReturn(['syncData' => ['settings' => []]]);
		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'admin/utils.twig', $this->callback(
				fn (array $data): bool => $data['syncData'] === ['settings' => []] && $data['oauthGrants'] === null,
			))
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action())($this->request, $this->response, ['page' => 'sync']);
	}

	public function testActionComesFromRouteArgsThenQuery(): void
	{
		$this->setupRoutingContext();
		$this->givenRequest('/admin/utils/sync', 'GET', ['action' => 'from-query']);
		$this->syncBuilder->expects($this->once())->method('build')
			->with($this->request, 'sync', 'from-query')
			->willReturn([]);
		$this->renderer->method('template')->willReturn($this->createMock(ResponseInterface::class));

		($this->action())($this->request, $this->response, ['page' => 'sync']);
	}

	public function testIncludesPostDataWhenMethodIsPost(): void
	{
		$this->setupRoutingContext();
		$this->givenRequest('/admin/utils/logs', 'POST');
		$this->request->method('getParsedBody')->willReturn(['a' => 'b']);
		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'admin/utils.twig', $this->callback(
				fn (array $data): bool => $data['postData'] === ['a' => 'b'],
			))
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action())($this->request, $this->response, ['page' => 'logs']);
	}

	public function testIncludesEmptyPostDataForGetRequest(): void
	{
		$this->setupRoutingContext();
		$this->givenRequest('/admin/utils/logs');
		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'admin/utils.twig', $this->callback(
				fn (array $data): bool => $data['postData'] === [],
			))
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action())($this->request, $this->response, ['page' => 'logs']);
	}

	public function testImportPagesAreEditionGated(): void
	{
		$this->assertPageIsEditionGated('import-rss');
	}

	public function testImportWordpressPageIsEditionGated(): void
	{
		$this->assertPageIsEditionGated('import-wordpress');
	}

	private function assertPageIsEditionGated(string $page): void
	{
		$editions = $this->createMock(EditionFeatureService::class);
		$editions->method('can')->willReturn(false);
		$this->setupRoutingContext();
		$this->givenRequest("/admin/utils/{$page}");
		$this->request->method('getHeaderLine')->willReturn('');
		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'access-denied.twig', $this->anything())
			->willReturn($this->createMock(ResponseInterface::class));

		$action = new AdminUtilsAction($this->renderer, $editions, new UtilsPageDataResolver([]));
		$action($this->request, $this->response, ['page' => $page]);
	}

	public function testIncludesUrlData(): void
	{
		$this->setupRoutingContext();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/logs');
		$uri->method('getQuery')->willReturn('a=1');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn(['a' => '1']);

		$this->renderer->expects($this->once())->method('template')
			->with($this->response, 'admin/utils.twig', $this->callback(
				fn (array $data): bool => $data['url']['path'] === '/admin/utils/logs'
					&& $data['url']['query'] === 'a=1'
					&& $data['url']['params'] === ['page' => 'logs']
					&& $data['url']['page'] === 'utils',
			))
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action())($this->request, $this->response, ['page' => 'logs']);
	}
}
