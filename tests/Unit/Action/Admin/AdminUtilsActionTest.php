<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\TwigRenderer;

final class AdminUtilsActionTest extends TestCase
{
	private AdminUtilsAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer       = $this->createMock(TwigRenderer::class);
		$this->twigEngine     = $this->createMock(TwigEngine::class);
		$this->apiKeyFetcher  = $this->createMock(ApiKeyFetcher::class);
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$this->action = new AdminUtilsAction($this->renderer, $this->twigEngine, $this->apiKeyFetcher);
	}

	public function testRendersUtilsTemplateWithDefaultPage(): void
	{
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
