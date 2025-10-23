<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Import\ImportCsvAction;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportCsvActionTest extends TestCase
{
	private ImportCsvAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $csvImporter;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->csvImporter = $this->createMock(CsvImporter::class);
		$this->renderer    = $this->createMock(JsonRenderer::class);
		$this->request     = $this->createMock(ServerRequestInterface::class);
		$this->response    = $this->createMock(ResponseInterface::class);

		$this->action = new ImportCsvAction($this->csvImporter, $this->renderer);
	}

	public function testImportsCsvSuccessfully(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['csv' => $file]);

		$this->csvImporter->expects($this->once())
			->method('import')
			->with('products', $file, false)
			->willReturn(10);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['import_count' => 10])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($this->response, $result);
	}

	public function testThrowsExceptionWhenFileNotUploaded(): void
	{
		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn([]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionWhenUploadHasError(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_PARTIAL);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['csv' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testSupportsUpdateMode(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn(['update' => 'yes']);
		$this->request->method('getUploadedFiles')->willReturn(['csv' => $file]);

		$this->csvImporter->expects($this->once())
			->method('import')
			->with('products', $file, true)
			->willReturn(5);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testSupportsQueueMode(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn(['queue' => '1']);
		$this->request->method('getUploadedFiles')->willReturn(['csv' => $file]);

		$this->csvImporter->expects($this->once())
			->method('queueJobs');

		$this->csvImporter->expects($this->once())
			->method('import')
			->willReturn(20);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturnsImportCount(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['csv' => $file]);

		$this->csvImporter->method('import')->willReturn(15);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(fn ($data): bool => isset($data['import_count']) && $data['import_count'] === 15))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}
}
