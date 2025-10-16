<?php

namespace Tests\Unit\Action\Property;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\PropertyClearCacheAction;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;

final class PropertyClearCacheActionTest extends TestCase
{
	private PropertyClearCacheAction $action;
	private PropertyCacheCleaner $service;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->service  = $this->createMock(PropertyCacheCleaner::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new PropertyClearCacheAction($this->renderer, $this->service);
	}

	public function testClearsCacheSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'image',
		];

		$this->service->expects($this->once())
			->method('deletePropertyCache')
			->with('products', 'product-1', 'image')
			->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['deleted' => true])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesArgsToService(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'featuredImage',
		];

		$this->service->expects($this->once())
			->method('deletePropertyCache')
			->with('blog', 'post-5', 'featuredImage')
			->willReturn(true);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonWithDeletedStatus(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
		];

		$this->service->method('deletePropertyCache')->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return isset($data['deleted']) && $data['deleted'] === true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturns500WhenDeleteFails(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
		];

		$this->service->method('deletePropertyCache')->willReturn(false);

		$response500 = $this->createMock(ResponseInterface::class);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$this->renderer->expects($this->once())
			->method('json')
			->with($response500, ['deleted' => false])
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($response500, $result);
	}
}
