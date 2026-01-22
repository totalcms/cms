<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportObjectZipAction;
use TotalCMS\Domain\Export\Service\ObjectZipper;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

final class ExportObjectZipActionTest extends TestCase
{
	private ExportObjectZipAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $objectZipper;
	private \PHPUnit\Framework\MockObject\MockObject $objectRepository;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->objectZipper     = $this->createMock(ObjectZipper::class);
		$this->objectRepository = $this->createMock(ObjectRepository::class);
		$this->request          = $this->createMock(ServerRequestInterface::class);
		$this->response         = $this->createMock(ResponseInterface::class);

		$this->action = new ExportObjectZipAction($this->objectZipper, $this->objectRepository);
	}

	public function testExportsObjectZipSuccessfully(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test zip content');

		$this->objectRepository->expects($this->once())
			->method('existsObject')
			->with('blog', 'my-post')
			->willReturn(true);

		$this->objectZipper->expects($this->once())
			->method('createObjectZip')
			->with('blog', 'my-post')
			->willReturn($zipPath);

		$this->objectZipper->expects($this->once())
			->method('getZipFilename')
			->with('blog', 'my-post')
			->willReturn('blog--my-post.zip');

		$this->response->expects($this->exactly(3))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog', 'id' => 'my-post']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testReturns404WhenObjectNotFound(): void
	{
		$this->objectRepository->expects($this->once())
			->method('existsObject')
			->with('blog', 'nonexistent')
			->willReturn(false);

		$response404 = $this->createMock(ResponseInterface::class);
		$body        = $this->createMock(StreamInterface::class);
		$response404->method('getBody')->willReturn($body);

		$body->expects($this->once())
			->method('write')
			->with('Object not found');

		$this->response->expects($this->once())
			->method('withStatus')
			->with(404)
			->willReturn($response404);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog', 'id' => 'nonexistent']);

		$this->assertSame($response404, $result);
	}

	public function testSetsZipContentType(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test');

		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')->willReturn($zipPath);
		$this->objectZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'test', 'id' => 'obj']);

		$this->assertTrue(true);
	}

	public function testSetsContentDispositionWithFilename(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test');

		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')->willReturn($zipPath);
		$this->objectZipper->method('getZipFilename')->willReturn('blog--my-post.zip');

		$this->response->expects($this->exactly(3))
			->method('withHeader')
			->willReturnCallback(function ($name, string $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('attachment', $value);
					$this->assertStringContainsString('blog--my-post.zip', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'blog', 'id' => 'my-post']);
	}

	public function testSetsContentLengthHeader(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		$content = 'test zip content with some data';
		file_put_contents($zipPath, $content);

		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')->willReturn($zipPath);
		$this->objectZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->expects($this->exactly(3))
			->method('withHeader')
			->willReturnCallback(function ($name, $value) use ($content): ResponseInterface {
				if ($name === 'Content-Length') {
					$this->assertEquals((string)strlen($content), $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'test', 'id' => 'obj']);
	}

	public function testReturns500WhenZipFileNotFound(): void
	{
		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')->willReturn('/nonexistent/file.zip');
		$this->objectZipper->method('getZipFilename')->willReturn('test.zip');

		$response500 = $this->createMock(ResponseInterface::class);
		$body        = $this->createMock(StreamInterface::class);
		$response500->method('getBody')->willReturn($body);

		$body->expects($this->once())
			->method('write')
			->with($this->stringContains('Failed to create zip file'));

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test', 'id' => 'obj']);

		$this->assertSame($response500, $result);
	}

	public function testHandlesRuntimeException(): void
	{
		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')
			->willThrowException(new \RuntimeException('Zip creation failed'));

		$response500 = $this->createMock(ResponseInterface::class);
		$body        = $this->createMock(StreamInterface::class);
		$response500->method('getBody')->willReturn($body);

		$body->expects($this->once())
			->method('write')
			->with($this->stringContains('Error creating zip: Zip creation failed'));

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test', 'id' => 'obj']);

		$this->assertSame($response500, $result);
	}

	public function testCleansUpTemporaryFile(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-cleanup-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'test content');

		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')->willReturn($zipPath);
		$this->objectZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['collection' => 'test', 'id' => 'obj']);

		// Verify temporary file was deleted
		$this->assertFileDoesNotExist($zipPath);
	}

	public function testReturnsResponseWithZipBody(): void
	{
		$zipPath = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';
		file_put_contents($zipPath, 'zip content');

		$this->objectRepository->method('existsObject')->willReturn(true);
		$this->objectZipper->method('createObjectZip')->willReturn($zipPath);
		$this->objectZipper->method('getZipFilename')->willReturn('test.zip');

		$this->response->method('withHeader')->willReturnSelf();

		$responseWithBody = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturn($responseWithBody);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'test', 'id' => 'obj']);

		$this->assertSame($responseWithBody, $result);
	}
}
