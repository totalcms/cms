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
	private TwigRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

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
				$this->callback(function ($data) {
					return isset($data['content'])
						&& isset($data['page'])
						&& $data['page'] === 'index';
				})
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
				$this->callback(function ($data) {
					return isset($data['page']) && $data['page'] === 'jumpstart';
				})
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
				$this->callback(function ($data) {
					// Should fall back to index page due to .. prevention
					return $data['page'] === 'index';
				})
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
				$this->callback(function ($data) {
					// Should fall back to index due to invalid characters
					return $data['page'] === 'index';
				})
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

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(function ($data) {
					// Should fall back to index if page doesn't exist
					return $data['page'] === 'index';
				})
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

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(function ($data) {
					// Should preserve subdirectory path if file exists, otherwise falls back to index
					return isset($data['page'])
						&& ($data['page'] === 'field-settings/text' || $data['page'] === 'index');
				})
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

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(function ($data) {
					// Backslashes should be converted to forward slashes
					return !str_contains($data['page'], '\\');
				})
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

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(function ($data) {
					// Double slashes should be normalized
					return !str_contains($data['page'], '//');
				})
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

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(function ($data) {
					return isset($data['url'])
						&& isset($data['url']['path'])
						&& isset($data['url']['query'])
						&& $data['url']['page'] === 'docs';
				})
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

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/docs.twig',
				$this->callback(function ($data) {
					// Should trim leading/trailing slashes
					$page = $data['page'];
					return $page !== '' && $page[0] !== '/' && substr($page, -1) !== '/';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => '/page/']);

		$this->assertSame($expectedResponse, $result);
	}
}
