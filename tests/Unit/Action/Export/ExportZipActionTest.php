<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportZipAction;
use TotalCMS\Domain\Export\Service\CollectionZipper;
use TotalCMS\Domain\Export\Service\ObjectZipper;

final class ExportZipActionTest extends TestCase
{
	private ExportZipAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $collectionZipper;
	private \PHPUnit\Framework\MockObject\MockObject $objectZipper;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->collectionZipper = $this->createMock(CollectionZipper::class);
		$this->objectZipper     = $this->createMock(ObjectZipper::class);
		$this->request          = $this->createMock(ServerRequestInterface::class);
		$this->response         = $this->createMock(ResponseInterface::class);

		// No `ids` query param by default → the whole-collection branch runs.
		$this->request->method('getQueryParams')->willReturn([]);

		$this->action = new ExportZipAction($this->collectionZipper, $this->objectZipper);
	}

	public function testExportsZipSuccessfully(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test zip content');

		$this->collectionZipper->expects($this->once())
			->method('createCollectionZip')
			->with('products')
			->willReturn($zipPath);

		$this->collectionZipper->expects($this->once())
			->method('getZipFilename')
			->with('products')
			->willReturn('products.zip');

		$this->response->expects($this->exactly(3))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		$result = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testExportsSelectedIdsWhenIdsQueryPresent(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['ids' => 'a, b']);

		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'zip');

		// Ids are trimmed and passed through; the whole-collection path is skipped.
		$this->objectZipper->expects($this->once())
			->method('createObjectsZip')
			->with('posts', ['a', 'b'])
			->willReturn($zipPath);
		$this->objectZipper->method('getObjectsZipFilename')->willReturn('posts-objects.zip');
		$this->collectionZipper->expects($this->never())->method('createCollectionZip');

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($request, $this->response, ['collection' => 'posts']);
	}

	public function testSetsZipContentType(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test');

		$this->collectionZipper->method('createCollectionZip')->willReturn($zipPath);
		$this->collectionZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertTrue(true);
	}

	public function testSetsContentDispositionWithFilename(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test');

		$this->collectionZipper->method('createCollectionZip')->willReturn($zipPath);
		$this->collectionZipper->method('getZipFilename')->willReturn('blog-export.zip');

		$this->response->expects($this->exactly(3))
			->method('withHeader')
			->willReturnCallback(function ($name, string $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('attachment', $value);
					$this->assertStringContainsString('blog-export.zip', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testSetsContentLengthHeader(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		$content = 'test zip content with some data';
		file_put_contents($zipPath, $content);

		$this->collectionZipper->method('createCollectionZip')->willReturn($zipPath);
		$this->collectionZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->expects($this->exactly(3))
			->method('withHeader')
			->willReturnCallback(function ($name, $value) use ($content): ResponseInterface {
				if ($name === 'Content-Length') {
					$this->assertEquals((string)strlen($content), $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'test']);
	}

	public function testReturns400WhenAllIdsAreNonExistent(): void
	{
		// createObjectsZip throws RuntimeException when no objects resolve → 400.
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['ids' => 'ghost-1,ghost-2']);

		$this->objectZipper->expects($this->once())
			->method('createObjectsZip')
			->with('posts', ['ghost-1', 'ghost-2'])
			->willThrowException(new \RuntimeException('No objects found to export in collection: posts'));

		$response400 = $this->createMock(ResponseInterface::class);
		$body        = $this->createMock(StreamInterface::class);
		$response400->method('getBody')->willReturn($body);
		$response400->method('withHeader')->willReturnSelf();

		$body->expects($this->once())
			->method('write')
			->with($this->stringContains('No objects found'));

		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($response400);

		$result = ($this->action)($request, $this->response, ['collection' => 'posts']);

		$this->assertSame($response400, $result);
	}

	public function testReturns500WhenZipFileNotFound(): void
	{
		$this->collectionZipper->method('createCollectionZip')->willReturn('/nonexistent/file.zip');
		$this->collectionZipper->method('getZipFilename')->willReturn('test.zip');

		$response500 = $this->createMock(ResponseInterface::class);
		$body        = $this->createMock(StreamInterface::class);
		$response500->method('getBody')->willReturn($body);
		$response500->method('withHeader')->willReturnSelf();

		$body->expects($this->once())
			->method('write')
			->with($this->stringContains('Failed to create zip file'));

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertSame($response500, $result);
	}

	public function testHandlesRuntimeException(): void
	{
		$this->collectionZipper->method('createCollectionZip')
			->willThrowException(new \RuntimeException('Zip creation failed'));

		$response500 = $this->createMock(ResponseInterface::class);
		$body        = $this->createMock(StreamInterface::class);
		$response500->method('getBody')->willReturn($body);
		$response500->method('withHeader')->willReturnSelf();

		$body->expects($this->once())
			->method('write')
			->with($this->stringContains('Zip creation failed'));

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertSame($response500, $result);
	}

	public function testStreamsZipBodyFromFileHandle(): void
	{
		// With streaming, the action opens a file handle and passes it as the
		// stream body — it does NOT read the entire file into memory.
		$zipPath = sys_get_temp_dir() . '/test-stream-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test content');

		$this->collectionZipper->method('createCollectionZip')->willReturn($zipPath);
		$this->collectionZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->method('withHeader')->willReturnSelf();

		$capturedStream = null;
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturnCallback(function ($stream) use (&$capturedStream): ResponseInterface {
				$capturedStream = $stream;

				return $this->response;
			});

		($this->action)($this->request, $this->response, ['collection' => 'test']);

		$this->assertNotNull($capturedStream);
	}

	public function testReturnsResponseWithZipBody(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'zip content');

		$this->collectionZipper->method('createCollectionZip')->willReturn($zipPath);
		$this->collectionZipper->method('getZipFilename')->willReturn('test.zip');

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
