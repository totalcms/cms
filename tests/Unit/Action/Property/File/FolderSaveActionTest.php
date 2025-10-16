<?php

namespace Tests\Unit\Action\Property\File;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\File\FolderSaveAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\DepotFolderSaver;
use TotalCMS\Renderer\JsonRenderer;

final class FolderSaveActionTest extends TestCase
{
	private FolderSaveAction $action;
	private DepotFolderSaver $saver;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->saver    = $this->createMock(DepotFolderSaver::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new FolderSaveAction($this->renderer, $this->saver);
	}

	public function testCreatesFolderSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'documents',
		];

		$body = ['path' => 'subfolder/new-folder'];

		$this->request->method('getParsedBody')->willReturn($body);

		$objectData = $this->createMock(ObjectData::class);

		$this->saver->expects($this->once())
			->method('createFolder')
			->with('products', 'product-1', 'documents', 'subfolder/new-folder')
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToSaver(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'attachments',
		];

		$body = ['path' => 'documents/2024'];

		$this->request->method('getParsedBody')->willReturn($body);

		$objectData = $this->createMock(ObjectData::class);

		$this->saver->expects($this->once())
			->method('createFolder')
			->with('blog', 'post-5', 'attachments', 'documents/2024')
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesPathFromBody(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'files',
		];

		$body = ['path' => 'custom/path/folder'];

		$this->request->method('getParsedBody')->willReturn($body);

		$objectData = $this->createMock(ObjectData::class);

		$this->saver->expects($this->once())
			->method('createFolder')
			->with($this->anything(), $this->anything(), $this->anything(), 'custom/path/folder')
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
		];

		$body = ['path' => 'images/2024'];

		$this->request->method('getParsedBody')->willReturn($body);

		$objectData = $this->createMock(ObjectData::class);

		$this->saver->method('createFolder')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsUpdatedObject(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'depot',
		];

		$body = ['path' => 'folder'];

		$this->request->method('getParsedBody')->willReturn($body);

		$objectData = $this->createMock(ObjectData::class);

		$this->saver->method('createFolder')->willReturn($objectData);
		$this->renderer->method('jsonItem')->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}
}
