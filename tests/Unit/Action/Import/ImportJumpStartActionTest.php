<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Import\ImportJumpStartAction;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

final class ImportJumpStartActionTest extends TestCase
{
	private ImportJumpStartAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $jumpStartImporter;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->jumpStartImporter = $this->createMock(JumpStartImporter::class);
		$this->renderer          = $this->createMock(JsonRenderer::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);

		$this->action = new ImportJumpStartAction($this->jumpStartImporter, $this->renderer);
	}

	public function testImportsDemoDefinition(): void
	{
		$demoResult = OperationResult::success('Import completed successfully', [
			'collections' => [],
			'schemas'     => [],
		]);

		$this->request->method('getQueryParams')->willReturn(['demo' => 'true']);

		$this->jumpStartImporter->expects($this->once())
			->method('importDemoDefinition')
			->willReturn($demoResult);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $demoResult->toArray())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($this->response, $result);
	}

	public function testImportsFromUploadedFile(): void
	{
		$definition  = ['name' => 'My Project', 'collections' => []];
		$jsonContent = json_encode($definition);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($jsonContent);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$result = OperationResult::success('Import completed successfully', ['collections' => 5]);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition)
			->willReturn($result);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $result->toArray())
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionWhenFileNotUploaded(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn([]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionWhenUploadHasError(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_NO_FILE);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionForInvalidJson(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn('invalid json');

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid JSON format');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionForNonArrayJson(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn('"string"');

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid JSON format');

		($this->action)($this->request, $this->response);
	}

	public function testDemoModeUsesImportDemoDefinition(): void
	{
		$this->request->method('getQueryParams')->willReturn(['demo' => 'true']);

		$this->jumpStartImporter->expects($this->once())
			->method('importDemoDefinition')
			->willReturn(OperationResult::success());

		$this->jumpStartImporter->expects($this->never())
			->method('importFromDefinition');

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}
}
