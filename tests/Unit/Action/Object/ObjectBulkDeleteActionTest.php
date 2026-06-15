<?php

namespace Tests\Unit\Action\Object;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Object\ObjectBulkDeleteAction;
use TotalCMS\Domain\Object\Service\BulkObjectService;
use TotalCMS\Renderer\JsonRenderer;

final class ObjectBulkDeleteActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $bulk;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;
	private ObjectBulkDeleteAction $action;

	protected function setUp(): void
	{
		$this->bulk     = $this->createMock(BulkObjectService::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);
		$this->action   = new ObjectBulkDeleteAction($this->renderer, $this->bulk);
	}

	public function testDeletesTheProvidedIds(): void
	{
		$this->request->method('getParsedBody')->willReturn(['ids' => ['a', 'b', 'c']]);

		$this->bulk->expects($this->once())
			->method('deleteMany')
			->with('blog', ['a', 'b', 'c'])
			->willReturn(['deleted' => ['a', 'b', 'c'], 'failed' => []]);

		$this->renderer->expects($this->once())
			->method('json')
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog']);

		$this->assertSame($this->response, $result);
	}

	public function testTrimsBlankIdsBeforeDeleting(): void
	{
		$this->request->method('getParsedBody')->willReturn(['ids' => ['a', ' ', '', 'b']]);

		$this->bulk->expects($this->once())
			->method('deleteMany')
			->with('blog', ['a', 'b'])
			->willReturn(['deleted' => ['a', 'b'], 'failed' => []]);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testThrowsWhenIdsMissing(): void
	{
		$this->request->method('getParsedBody')->willReturn([]);
		$this->bulk->expects($this->never())->method('deleteMany');

		$this->expectException(HttpBadRequestException::class);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testThrowsWhenIdsEmptyAfterTrimming(): void
	{
		$this->request->method('getParsedBody')->willReturn(['ids' => ['', '   ']]);
		$this->bulk->expects($this->never())->method('deleteMany');

		$this->expectException(HttpBadRequestException::class);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testThrowsWhenIdsNotAList(): void
	{
		$this->request->method('getParsedBody')->willReturn(['ids' => 'a,b']);
		$this->bulk->expects($this->never())->method('deleteMany');

		$this->expectException(HttpBadRequestException::class);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}
}
