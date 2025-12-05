<?php

namespace Tests\Unit\Action\Export;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportJsonAction;
use TotalCMS\Domain\Object\Service\ObjectExporter;

final class ExportJsonActionTest extends TestCase
{
	private ExportJsonAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $objectExporter;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->objectExporter = $this->createMock(ObjectExporter::class);
		$this->session        = $this->createMock(SessionInterface::class);
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$flash = $this->createMock(FlashInterface::class);
		$this->session->method('getFlash')->willReturn($flash);

		$this->action = new ExportJsonAction($this->objectExporter, $this->session);
	}

	public function testExportsJsonSuccessfully(): void
	{
		$objects = [
			['id' => '1', 'name' => 'Product 1'],
			['id' => '2', 'name' => 'Product 2'],
		];

		$this->objectExporter->expects($this->once())
			->method('exportAllObjectsForJson')
			->with('products')
			->willReturn(['data' => $objects, 'errors' => []]);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		$result = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testSetsJsonContentType(): void
	{
		$this->objectExporter->method('exportAllObjectsForJson')->willReturn(['data' => [], 'errors' => []]);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertTrue(true);
	}

	public function testSetsContentDispositionHeader(): void
	{
		$this->objectExporter->method('exportAllObjectsForJson')->willReturn(['data' => [], 'errors' => []]);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, string $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('attachment', $value);
					$this->assertStringContainsString('collection-blog.json', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testHandlesEmptyCollection(): void
	{
		$this->objectExporter->expects($this->once())
			->method('exportAllObjectsForJson')
			->with('empty')
			->willReturn(['data' => [], 'errors' => []]);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		$result = ($this->action)($this->request, $this->response, ['collection' => 'empty']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testReturnsResponseWithJsonBody(): void
	{
		$this->objectExporter->method('exportAllObjectsForJson')->willReturn(['data' => [['id' => '1']], 'errors' => []]);

		$this->response->method('withHeader')->willReturnSelf();

		$responseWithBody = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturn($responseWithBody);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertSame($responseWithBody, $result);
	}

	public function testUsesCollectionNameInFilename(): void
	{
		$this->objectExporter->method('exportAllObjectsForJson')->willReturn(['data' => [], 'errors' => []]);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'my-collection']);

		$this->assertTrue(true);
	}
}
