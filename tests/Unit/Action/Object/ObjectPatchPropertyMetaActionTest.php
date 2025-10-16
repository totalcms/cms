<?php

namespace Tests\Unit\Action\Object;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\ObjectPatchPropertyMetaAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Renderer\JsonRenderer;

final class ObjectPatchPropertyMetaActionTest extends TestCase
{
	private ObjectPatchPropertyMetaAction $action;
	private ObjectPatcher $objectPatcher;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->objectPatcher = $this->createMock(ObjectPatcher::class);
		$this->renderer      = $this->createMock(JsonRenderer::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new ObjectPatchPropertyMetaAction($this->renderer, $this->objectPatcher);
	}

	public function testPatchesPropertyMetaSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
			'name'       => 'image1.jpg',
		];

		$data = ['alt' => 'Updated alt text'];

		$this->request->method('getParsedBody')->willReturn($data);
		$this->request->method('getQueryParams')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectPatcher->expects($this->once())
			->method('patchObjectPropertyMeta')
			->with('products', 'product-1', 'gallery', 'image1.jpg', $data, null)
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesOptionalPathParameter(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'images',
			'name'       => 'header.jpg',
		];

		$data = ['tags' => ['featured']];

		$this->request->method('getParsedBody')->willReturn($data);
		$this->request->method('getQueryParams')->willReturn(['path' => 'uploads/2024']);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectPatcher->expects($this->once())
			->method('patchObjectPropertyMeta')
			->with('blog', 'post-5', 'images', 'header.jpg', $data, 'uploads/2024')
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesNullWhenNoPathParameter(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'files',
			'name'       => 'doc.pdf',
		];

		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectPatcher->expects($this->once())
			->method('patchObjectPropertyMeta')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), null)
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesBodyDataToPatcher(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
			'name'       => 'image1.jpg',
		];

		$data = ['alt' => 'Partial update'];

		$this->request->method('getParsedBody')->willReturn($data);
		$this->request->method('getQueryParams')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectPatcher->expects($this->once())
			->method('patchObjectPropertyMeta')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $data, $this->anything())
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
			'name'       => 'file.jpg',
		];

		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->objectPatcher->method('patchObjectPropertyMeta')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}
