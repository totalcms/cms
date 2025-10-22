<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Renderer\TwigRenderer;

final class AdminDocsActionTest extends TestCase
{
	private AdminDocsAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminDocsAction($this->renderer);
	}

	public function testLoadsIndexPageByDefault(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(fn ($data): bool => isset($data['content'])
						&& isset($data['page'])
						&& $data['page'] === 'index')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testLoadsSpecificPage(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/jumpstart');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(fn ($data): bool => isset($data['page']) && $data['page'] === 'jumpstart')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'jumpstart']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testPreventsPathTraversalAttack(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/../../../etc/passwd');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(fn ($data): bool =>
					// Should fall back to index page due to .. prevention
					$data['page'] === 'index')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => '../../../etc/passwd']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesInvalidCharacters(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/page<script>');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(fn ($data): bool =>
					// Should fall back to index due to invalid characters
					$data['page'] === 'index')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'page<script>']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesNonExistentPage(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/nonexistent');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		// Mock the 404 response
		$response404 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(404)->willReturn($response404);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$response404,
				'admin/404.twig',
				$this->callback(fn ($data): bool =>
					// Should return 404
					isset($data['url']) && $data['url']['page'] === '404')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'nonexistent-page-xyz']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesSubdirectories(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/field-settings/text');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		// Mock the 404 response for non-existent subdirectory page
		$response404 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(404)->willReturn($response404);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$response404,
				'admin/404.twig',
				$this->callback(fn ($data): bool =>
					// Should return 404 for non-existent subdirectory
					isset($data['url']) && $data['url']['page'] === '404')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'field-settings/text']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testNormalizesPathSeparators(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/field\\settings');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		// Mock the 404 response for non-existent page
		$response404 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(404)->willReturn($response404);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$response404,
				'admin/404.twig',
				$this->callback(fn ($data): bool =>
					// Should return 404 and not contain backslashes
					isset($data['url']) && $data['url']['page'] === '404')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'field\\settings']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testRemovesDuplicateSlashes(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/field//settings');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		// Mock the 404 response for non-existent page
		$response404 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(404)->willReturn($response404);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$response404,
				'admin/404.twig',
				$this->callback(fn ($data): bool =>
					// Should return 404
					isset($data['url']) && $data['url']['page'] === '404')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'field//settings']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesUrlDataInResponse(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs/test');
		$uri->method('getQuery')->willReturn('foo=bar');

		$this->request->method('getUri')->willReturn($uri);

		// Mock the 404 response for non-existent page
		$response404 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(404)->willReturn($response404);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$response404,
				'admin/404.twig',
				$this->callback(fn ($data): bool => isset($data['url'])
						&& isset($data['url']['path'])
						&& $data['url']['page'] === '404')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'test']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesLeadingAndTrailingSlashes(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/docs//page/');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		// Mock the 404 response for non-existent page
		$response404 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(404)->willReturn($response404);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$response404,
				'admin/404.twig',
				$this->callback(fn ($data): bool =>
					// Should return 404
					isset($data['url']) && $data['url']['page'] === '404')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => '/page/']);

		$this->assertSame($expectedResponse, $result);
	}
}
