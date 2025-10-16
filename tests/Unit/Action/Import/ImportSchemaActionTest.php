<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Import\ImportSchemaAction;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Renderer\JsonRenderer;

final class ImportSchemaActionTest extends TestCase
{
	private ImportSchemaAction $action;
	private SchemaSaver $schemaSaver;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->schemaSaver = $this->createMock(SchemaSaver::class);
		$this->renderer    = $this->createMock(JsonRenderer::class);
		$this->request     = $this->createMock(ServerRequestInterface::class);
		$this->response    = $this->createMock(ResponseInterface::class);

		$this->action = new ImportSchemaAction($this->schemaSaver, $this->renderer);
	}

	public function testImportsSchemaSuccessfully(): void
	{
		$schemaArray = ['id' => 'blog', 'name' => 'Blog', 'properties' => []];
		$jsonContent = json_encode($schemaArray);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($jsonContent);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getUploadedFiles')->willReturn(['schema' => $file]);

		$schemaData = $this->createMock(SchemaData::class);
		$schemaData->method('toArray')->willReturn($schemaArray);

		$this->schemaSaver->expects($this->once())
			->method('saveSchema')
			->with($schemaArray)
			->willReturn($schemaData);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $schemaArray)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($this->response, $result);
	}

	public function testThrowsExceptionWhenFileNotUploaded(): void
	{
		$this->request->method('getUploadedFiles')->willReturn([]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionWhenUploadHasError(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_CANT_WRITE);

		$this->request->method('getUploadedFiles')->willReturn(['schema' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionForInvalidJson(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn('invalid json');

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getUploadedFiles')->willReturn(['schema' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid JSON');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionForInvalidSchemaData(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn('{"invalid": "schema"}');

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getUploadedFiles')->willReturn(['schema' => $file]);

		$this->schemaSaver->method('saveSchema')
			->willThrowException(new \InvalidArgumentException('Missing id field'));

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid schema data');

		($this->action)($this->request, $this->response);
	}

	public function testReturnsSchemaArray(): void
	{
		$schemaArray = ['id' => 'product', 'name' => 'Product'];
		$jsonContent = json_encode($schemaArray);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($jsonContent);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getUploadedFiles')->willReturn(['schema' => $file]);

		$schemaData = $this->createMock(SchemaData::class);
		$schemaData->method('toArray')->willReturn($schemaArray);

		$this->schemaSaver->method('saveSchema')->willReturn($schemaData);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return isset($data['id']) && $data['id'] === 'product';
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}
}
