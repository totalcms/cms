<?php

namespace Tests\Unit\Action\Property\File;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\File\FileDeleteAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Renderer\JsonRenderer;

final class FileDeleteActionTest extends TestCase
{
	private FileDeleteAction $action;
	private RemoverFactory $factory;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->factory  = $this->createMock(RemoverFactory::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new FileDeleteAction($this->renderer, $this->factory);
	}

	public function testDeletesFileSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'documents',
			'name'       => 'manual.pdf',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$remover = $this->createMock(FileRemover::class);

		$this->factory->expects($this->once())
			->method('generateRemoverService')
			->with('products', 'documents')
			->willReturn($remover);

		$objectData = $this->createMock(ObjectData::class);

		$remover->expects($this->once())
			->method('deleteFile')
			->with('products', 'product-1', 'documents', 'manual.pdf', null)
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
			'property'   => 'attachments',
			'name'       => 'document.pdf',
		];

		$this->request->method('getQueryParams')->willReturn(['path' => 'uploads/2024']);

		$remover = $this->createMock(FileRemover::class);

		$this->factory->method('generateRemoverService')->willReturn($remover);

		$objectData = $this->createMock(ObjectData::class);

		$remover->expects($this->once())
			->method('deleteFile')
			->with('blog', 'post-5', 'attachments', 'document.pdf', 'uploads/2024')
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
			'name'       => 'file.txt',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$remover = $this->createMock(FileRemover::class);

		$this->factory->method('generateRemoverService')->willReturn($remover);

		$objectData = $this->createMock(ObjectData::class);

		$remover->expects($this->once())
			->method('deleteFile')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), null)
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testGeneratesRemoverServiceForProperty(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
			'name'       => 'image.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$remover = $this->createMock(FileRemover::class);

		$this->factory->expects($this->once())
			->method('generateRemoverService')
			->with('products', 'gallery')
			->willReturn($remover);

		$objectData = $this->createMock(ObjectData::class);

		$remover->method('deleteFile')->willReturn($objectData);
		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
			'name'       => 'file.txt',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$remover = $this->createMock(FileRemover::class);

		$this->factory->method('generateRemoverService')->willReturn($remover);

		$objectData = $this->createMock(ObjectData::class);

		$remover->method('deleteFile')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}
