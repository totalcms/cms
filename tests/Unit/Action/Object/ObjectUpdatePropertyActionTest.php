<?php

namespace Tests\Unit\Action\Object;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\ObjectUpdatePropertyAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;

final class ObjectUpdatePropertyActionTest extends TestCase
{
	private ObjectUpdatePropertyAction $action;
	private ObjectUpdater $objectUpdater;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->renderer      = $this->createMock(JsonRenderer::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new ObjectUpdatePropertyAction($this->renderer, $this->objectUpdater);
	}

	public function testUpdatesPropertySuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'image',
		];

		$data = ['value' => 'new-image.jpg'];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with('products', 'product-1', 'image', $data)
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToUpdater(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'content',
		];

		$data = ['value' => 'Updated content'];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with('blog', 'post-5', 'content', $data)
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesBodyDataToUpdater(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
		];

		$data = [
			'value' => 'test value',
			'meta'  => ['key' => 'value'],
		];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->expects($this->once())
			->method('updateObjectProperty')
			->with($this->anything(), $this->anything(), $this->anything(), $data)
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

		$this->request->method('getParsedBody')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectUpdater->method('updateObjectProperty')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}
