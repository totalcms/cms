<?php

declare(strict_types=1);

namespace Tests\Unit\Renderer;

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use PHPUnit\Framework\TestCase;
use TotalCMS\Renderer\FileStreamRenderer;

// The byte-range logic (206 / 416 / full) and the two response shapes
// (attachment download, inline stream with cache validators) were written
// out in StreamAction and again in StreamUploadAction.
final class FileStreamRendererTest extends TestCase
{
	private function open(string $content)
	{
		return function () use ($content) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $content);
			rewind($stream);

			return $stream;
		};
	}

	public function testAFullStreamCarriesSizeDispositionAndCacheValidators(): void
	{
		$factory  = new Psr17Factory();
		$request  = $factory->createServerRequest('GET', '/stream/x');
		$response = (new FileStreamRenderer(new PhpSession()))->stream($request, $factory->createResponse(), 'video/mp4', 'clip.mp4', 10, $this->open('0123456789'), mtime: 1700000000);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('inline; filename="clip.mp4"', $response->getHeaderLine('Content-Disposition'));
		$this->assertSame('10', $response->getHeaderLine('Content-Length'));
		$this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
		$this->assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
		$this->assertSame('"' . dechex(1700000000) . '-a"', $response->getHeaderLine('ETag'));
		$this->assertStringEndsWith(' GMT', $response->getHeaderLine('Last-Modified'));
		$this->assertSame('0123456789', (string)$response->getBody());
	}

	public function testWithoutAModificationTimeNoValidatorsAreSent(): void
	{
		$factory  = new Psr17Factory();
		$response = (new FileStreamRenderer(new PhpSession()))->stream($factory->createServerRequest('GET', '/x'), $factory->createResponse(), 'video/mp4', 'clip.mp4', 3, $this->open('abc'));

		$this->assertFalse($response->hasHeader('ETag'));
		$this->assertFalse($response->hasHeader('Last-Modified'));
	}

	public function testARangeRequestGetsExactlyTheBytesAskedForAndNoAccelHeader(): void
	{
		$factory  = new Psr17Factory();
		$request  = $factory->createServerRequest('GET', '/stream/x')->withHeader('Range', 'bytes=2-5');
		$response = (new FileStreamRenderer(new PhpSession()))->stream($request, $factory->createResponse(), 'video/mp4', 'clip.mp4', 10, $this->open('0123456789'));

		$this->assertSame(206, $response->getStatusCode());
		$this->assertSame('bytes 2-5/10', $response->getHeaderLine('Content-Range'));
		$this->assertSame('4', $response->getHeaderLine('Content-Length'));
		$this->assertFalse($response->hasHeader('X-Accel-Buffering'));
		$this->assertSame('2345', (string)$response->getBody());
	}

	public function testAnOpenEndedRangeRunsToTheEnd(): void
	{
		$factory  = new Psr17Factory();
		$request  = $factory->createServerRequest('GET', '/x')->withHeader('Range', 'bytes=7-');
		$response = (new FileStreamRenderer(new PhpSession()))->stream($request, $factory->createResponse(), 'video/mp4', 'c', 10, $this->open('0123456789'));

		$this->assertSame('bytes 7-9/10', $response->getHeaderLine('Content-Range'));
		$this->assertSame('789', (string)$response->getBody());
	}

	public function testAnUnsatisfiableRangeIs416(): void
	{
		$factory  = new Psr17Factory();
		$request  = $factory->createServerRequest('GET', '/x')->withHeader('Range', 'bytes=10-12');
		$response = (new FileStreamRenderer(new PhpSession()))->stream($request, $factory->createResponse(), 'video/mp4', 'c', 10, $this->open('0123456789'));

		$this->assertSame(416, $response->getStatusCode());
		$this->assertSame('bytes */10', $response->getHeaderLine('Content-Range'));
	}

	public function testADownloadIsAnAttachment(): void
	{
		$factory  = new Psr17Factory();
		$response = (new FileStreamRenderer(new PhpSession()))->download($factory->createResponse(), 'application/pdf', 'report.pdf', 'PDF-BYTES');

		$this->assertSame('attachment; filename="report.pdf"', $response->getHeaderLine('Content-Disposition'));
		$this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
		$this->assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
		$this->assertSame('PDF-BYTES', (string)$response->getBody());
	}
}
