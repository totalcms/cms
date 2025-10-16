<?php

namespace Tests\Unit\Action\Property\File;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\File\FileMoveAction;
use TotalCMS\Domain\Property\Service\DepotFileMover;
use TotalCMS\Renderer\JsonRenderer;

final class FileMoveActionTest extends TestCase
{
	private FileMoveAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $mover;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->mover    = $this->createMock(DepotFileMover::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new FileMoveAction($this->renderer, $this->mover);
	}

	public function testMovesFileSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'documents',
			'name'       => 'manual.pdf',
		];

		$this->request->method('getQueryParams')->willReturn(['path' => 'uploads']);
		$this->request->method('getParsedBody')->willReturn(['destination' => 'archive']);

		$this->mover->expects($this->once())
			->method('moveFile')
			->with('products', 'product-1', 'documents', 'manual.pdf', 'uploads', 'archive')
			->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['moved' => true])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testReturns500WhenMoveFails(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'documents',
			'name'       => 'file.pdf',
		];

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->mover->method('moveFile')->willReturn(false);

		$response500 = $this->createMock(ResponseInterface::class);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$this->renderer->expects($this->once())
			->method('json')
			->with($response500, ['moved' => false])
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($response500, $result);
	}

	public function testUsesEmptyStringWhenParametersMissing(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'files',
			'name'       => 'file.txt',
		];

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->mover->expects($this->once())
			->method('moveFile')
			->with('test', 'test-1', 'files', 'file.txt', '', '')
			->willReturn(true);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesPathFromQueryParams(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'attachments',
			'name'       => 'doc.pdf',
		];

		$this->request->method('getQueryParams')->willReturn(['path' => 'old/path']);
		$this->request->method('getParsedBody')->willReturn(['destination' => 'new/path']);

		$this->mover->expects($this->once())
			->method('moveFile')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), 'old/path', 'new/path')
			->willReturn(true);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesDestinationFromBody(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'images',
			'name'       => 'photo.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getParsedBody')->willReturn(['destination' => 'gallery/2024']);

		$this->mover->expects($this->once())
			->method('moveFile')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), '', 'gallery/2024')
			->willReturn(true);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonWithMovedStatus(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'files',
			'name'       => 'file.txt',
		];

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->mover->method('moveFile')->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(fn ($data): bool => isset($data['moved']) && $data['moved'] === true))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}
