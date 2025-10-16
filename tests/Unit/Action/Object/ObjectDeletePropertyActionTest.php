<?php

namespace Tests\Unit\Action\Object;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\ObjectDeletePropertyAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Renderer\JsonRenderer;

final class ObjectDeletePropertyActionTest extends TestCase
{
	private ObjectDeletePropertyAction $action;
	private ObjectRemover $objectRemover;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->objectRemover = $this->createMock(ObjectRemover::class);
		$this->renderer      = $this->createMock(JsonRenderer::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new ObjectDeletePropertyAction($this->renderer, $this->objectRemover);
	}

	public function testDeletesPropertySuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'image',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->objectRemover->expects($this->once())
			->method('deleteObjectProperty')
			->with('products', 'product-1', 'image')
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesArgsToRemover(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'featuredImage',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->objectRemover->expects($this->once())
			->method('deleteObjectProperty')
			->with('blog', 'post-5', 'featuredImage')
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->objectRemover->method('deleteObjectProperty')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsUpdatedObject(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->objectRemover->method('deleteObjectProperty')->willReturn($objectData);
		$this->renderer->method('jsonItem')->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}
}
