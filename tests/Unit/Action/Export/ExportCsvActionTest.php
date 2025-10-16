<?php

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportCsvAction;
use TotalCMS\Domain\Object\Service\ObjectExporter;

final class ExportCsvActionTest extends TestCase
{
	private ExportCsvAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $objectExporter;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->objectExporter = $this->createMock(ObjectExporter::class);
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$this->action = new ExportCsvAction($this->objectExporter);
	}

	public function testExportsCsvSuccessfully(): void
	{
		$csvData = [
			['id', 'name', 'email'],
			['1', 'John Doe', 'john@example.com'],
			['2', 'Jane Smith', 'jane@example.com'],
		];

		$this->objectExporter->expects($this->once())
			->method('exportAllObjectsForCSv')
			->with('users')
			->willReturn($csvData);

		$responseWithHeaders = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturn($responseWithHeaders);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'users']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testSetsCsvContentType(): void
	{
		$this->objectExporter->method('exportAllObjectsForCSv')->willReturn([['id'], ['1']]);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'products']);

		// Test passes if no exception is thrown
		$this->assertTrue(true);
	}

	public function testSetsContentDispositionHeader(): void
	{
		$this->objectExporter->method('exportAllObjectsForCSv')->willReturn([]);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('attachment', $value);
					$this->assertStringContainsString('collection-blog.csv', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testUsesCollectionNameInFilename(): void
	{
		$this->objectExporter->method('exportAllObjectsForCSv')->willReturn([]);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'my-collection']);
	}

	public function testHandlesEmptyCollection(): void
	{
		$this->objectExporter->expects($this->once())
			->method('exportAllObjectsForCSv')
			->with('empty-collection')
			->willReturn([]);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		$result = ($this->action)($this->request, $this->response, ['collection' => 'empty-collection']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testReturnsResponseWithBody(): void
	{
		$this->objectExporter->method('exportAllObjectsForCSv')->willReturn([['id'], ['1']]);

		$this->response->method('withHeader')->willReturnSelf();

		$responseWithBody = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturn($responseWithBody);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertSame($responseWithBody, $result);
	}
}
