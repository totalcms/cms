<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Action\Import\ImportWordpressAction;
use TotalCMS\Domain\Import\WordpressImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportWordpressActionTest extends TestCase
{
	public function testReturnsBadRequestWhenNoFileUploaded(): void
	{
		$importer = $this->createMock(WordpressImporter::class);
		$renderer = $this->createMock(JsonRenderer::class);

		$request  = $this->createMock(ServerRequestInterface::class);
		$response = $this->createMock(ResponseInterface::class);

		$request->method('getUploadedFiles')->willReturn([]);

		$renderer->expects($this->once())
			->method('json')
			->with(
				$response,
				$this->callback(fn (array $data) => $data['success'] === false && str_contains($data['message'], 'Missing')),
				400,
			)
			->willReturn($response);

		$action = new ImportWordpressAction($importer, $renderer);
		$result = $action($request, $response);

		$this->assertSame($response, $result);
	}

	public function testReturnsBadRequestWhenCollectionMissing(): void
	{
		$importer = $this->createMock(WordpressImporter::class);
		$renderer = $this->createMock(JsonRenderer::class);

		$request  = $this->createMock(ServerRequestInterface::class);
		$response = $this->createMock(ResponseInterface::class);

		$uploadedFile = $this->createMock(UploadedFileInterface::class);
		$uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn('<rss></rss>');
		$uploadedFile->method('getStream')->willReturn($stream);

		$request->method('getUploadedFiles')->willReturn(['wordpress' => $uploadedFile]);
		$request->method('getParsedBody')->willReturn([]);

		$renderer->expects($this->once())
			->method('json')
			->with(
				$response,
				$this->callback(fn (array $data) => $data['success'] === false && str_contains($data['message'], 'collection')),
				400,
			)
			->willReturn($response);

		$action = new ImportWordpressAction($importer, $renderer);
		$result = $action($request, $response);

		$this->assertSame($response, $result);
	}
}
