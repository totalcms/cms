<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Import\ImportJsonAction;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportJsonActionTest extends TestCase
{
	private ImportJsonAction $action;
	private JsonImporter $jsonImporter;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->jsonImporter = $this->createMock(JsonImporter::class);
		$this->renderer     = $this->createMock(JsonRenderer::class);
		$this->request      = $this->createMock(ServerRequestInterface::class);
		$this->response     = $this->createMock(ResponseInterface::class);

		$this->action = new ImportJsonAction($this->jsonImporter, $this->renderer);
	}

	public function testImportsJsonSuccessfully(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['json' => $file]);

		$this->jsonImporter->expects($this->once())
			->method('import')
			->with('products', $file, false)
			->willReturn(5);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['import_count' => 5])
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
		$file->method('getError')->willReturn(UPLOAD_ERR_INI_SIZE);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['json' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testSupportsUpdateMode(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn(['update' => '1']);
		$this->request->method('getUploadedFiles')->willReturn(['json' => $file]);

		$this->jsonImporter->expects($this->once())
			->method('import')
			->with('products', $file, true)
			->willReturn(3);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testSupportsQueueMode(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn(['queue' => 'true']);
		$this->request->method('getUploadedFiles')->willReturn(['json' => $file]);

		$this->jsonImporter->expects($this->once())
			->method('queueJobs');

		$this->jsonImporter->expects($this->once())
			->method('import')
			->willReturn(10);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testSupportsUpdateAndQueueMode(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn(['update' => '1', 'queue' => 'true']);
		$this->request->method('getUploadedFiles')->willReturn(['json' => $file]);

		$this->jsonImporter->expects($this->once())
			->method('queueJobs');

		$this->jsonImporter->expects($this->once())
			->method('import')
			->with('products', $file, true)
			->willReturn(7);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturnsImportCount(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);

		$this->request->method('getAttribute')->with('collection')->willReturn('products');
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['json' => $file]);

		$this->jsonImporter->method('import')->willReturn(42);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return isset($data['import_count']) && $data['import_count'] === 42;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}
}
