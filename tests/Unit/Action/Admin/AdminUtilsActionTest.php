<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Renderer\TwigRenderer;

final class AdminUtilsActionTest extends TestCase
{
	private AdminUtilsAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $twigLintService;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $accessGroupLister;
	private \PHPUnit\Framework\MockObject\MockObject $collectionRepository;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $schemaLister;
	private \PHPUnit\Framework\MockObject\MockObject $rssImporter;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $settingsFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $templateLister;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer              = $this->createMock(TwigRenderer::class);
		$this->twigEngine            = $this->createMock(TwigEngine::class);
		$this->twigLintService       = $this->createMock(TwigLintService::class);
		$this->apiKeyFetcher         = $this->createMock(ApiKeyFetcher::class);
		$this->accessGroupLister     = $this->createMock(AccessGroupLister::class);
		$this->collectionRepository  = $this->createMock(CollectionRepository::class);
		$this->collectionFetcher     = $this->createMock(CollectionFetcher::class);
		$this->schemaLister          = $this->createMock(SchemaLister::class);
		$this->rssImporter           = $this->createMock(RssImporter::class);
		$this->editionFeatures       = $this->createMock(EditionFeatureService::class);
		$this->settingsFetcher       = $this->createMock(SettingsFetcher::class);
		$this->templateLister        = $this->createMock(TemplateLister::class);
		$this->request               = $this->createMock(ServerRequestInterface::class);
		$this->response              = $this->createMock(ResponseInterface::class);

		$this->action = new AdminUtilsAction(
			$this->renderer,
			$this->twigEngine,
			$this->twigLintService,
			$this->apiKeyFetcher,
			$this->accessGroupLister,
			$this->collectionRepository,
			$this->collectionFetcher,
			$this->schemaLister,
			$this->rssImporter,
			$this->editionFeatures,
			$this->settingsFetcher,
			$this->templateLister,
		);
	}

	/**
	 * Set up routing context attributes on the mocked request.
	 */
	private function setupRoutingContext(?string $routeName = null): void
	{
		$routeParser    = $this->createMock(\Slim\Interfaces\RouteParserInterface::class);
		$routingResults = $this->createMock(\Slim\Routing\RoutingResults::class);
		$route          = $routeName !== null
			? $this->createMock(\Slim\Routing\Route::class)
			: null;

		if ($route instanceof \PHPUnit\Framework\MockObject\MockObject) {
			$route->method('getName')->willReturn($routeName);
		}

		$this->request->method('getAttribute')
			->willReturnCallback(fn (string $name): \PHPUnit\Framework\MockObject\MockObject|string|null => match ($name) {
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

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['page'] === 'index'
						&& $data['url']['page'] === 'utils')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesTwigPlaygroundPostRequest(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/twig-playground');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn([
			'twig' => '{{ "Hello World" }}',
		]);

		$this->twigEngine->expects($this->once())
			->method('renderString')
			->with('{{ "Hello World" }}')
			->willReturn('Hello World');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['page'] === 'twig-playground'
						&& $data['results'] === 'Hello World')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'twig-playground']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesTwigPlaygroundErrors(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/twig-playground');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn([
			'twig' => '{{ invalid syntax',
		]);

		$this->twigEngine->expects($this->once())
			->method('renderString')
			->willThrowException(new \Exception('Syntax error'));

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => str_contains((string)$data['results'], 'error')
						&& str_contains((string)$data['results'], 'Syntax error'))
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'twig-playground']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesGetRequestWithoutProcessing(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/twig-playground');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$this->twigEngine->expects($this->never())->method('renderString');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['results'] === '')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'twig-playground']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesPostDataWhenMethodIsPost(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils');
		$uri->method('getQuery')->willReturn('');

		$postData = ['key' => 'value', 'foo' => 'bar'];

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn($postData);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['postData'] === $postData)
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesEmptyPostDataForGetRequest(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['postData'] === [])
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesProjectSetupPage(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/project-setup');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['page'] === 'project-setup'
						&& isset($data['totalcms1DetectionData']))
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'project-setup']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testNullDetectionDataForNonProjectSetupPages(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/other-page');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['totalcms1DetectionData'] === null)
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'other-page']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testOnlyProcessesTwigPlaygroundPage(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/other-page');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn([
			'twig' => '{{ "Should not process" }}',
		]);

		// TwigEngine should not be called for non-twig-playground pages
		$this->twigEngine->expects($this->never())->method('renderString');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('template')->willReturn($expectedResponse);

		($this->action)($this->request, $this->response, ['page' => 'other-page']);
	}

	public function testIncludesUrlData(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/cache');
		$uri->method('getQuery')->willReturn('action=clear');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$args = ['page' => 'cache'];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['url']['path'] === '/admin/utils/cache'
						&& $data['url']['query'] === 'action=clear'
						&& $data['url']['params'] === $args
						&& $data['url']['page'] === 'utils')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($expectedResponse, $result);
	}
}
