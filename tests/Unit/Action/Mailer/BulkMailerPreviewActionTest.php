<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Mailer\BulkMailerPreviewAction;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Renderer\RawRenderer;

final class BulkMailerPreviewActionTest extends TestCase
{
	private BulkMailerPreviewAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $bulkMailerService;
	private RawRenderer $renderer;

	protected function setUp(): void
	{
		$this->bulkMailerService = $this->createMock(BulkMailerService::class);
		$this->renderer          = new RawRenderer();

		$this->action = new BulkMailerPreviewAction(
			$this->bulkMailerService,
			$this->renderer,
		);
	}

	public function testReturnsErrorWhenMailerIdMissing(): void
	{
		$request  = $this->createRequest([
			'bulkPreviewObjectId' => 'obj-1',
			'bulkCollection'      => 'subscribers',
		]);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-error', $body);
		$this->assertStringContainsString('mailerId is required', $body);
	}

	public function testReturnsErrorWhenObjectIdMissing(): void
	{
		$request  = $this->createRequest([
			'mailerId'       => 'test-mailer',
			'bulkCollection' => 'subscribers',
		]);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-error', $body);
		$this->assertStringContainsString('objectId is required', $body);
	}

	public function testReturnsErrorWhenCollectionMissing(): void
	{
		$request  = $this->createRequest([
			'mailerId'            => 'test-mailer',
			'bulkPreviewObjectId' => 'obj-1',
		]);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-error', $body);
		$this->assertStringContainsString('Collection', $body);
	}

	public function testCallsServiceWithMappedFieldNames(): void
	{
		$request = $this->createRequest([
			'mailerId'            => 'test-mailer',
			'bulkPreviewObjectId' => 'obj-1',
			'bulkCollection'      => 'subscribers',
		]);
		$response = $this->createResponse();

		$this->bulkMailerService->expects($this->once())
			->method('previewEmail')
			->with('test-mailer', 'obj-1', 'subscribers')
			->willReturn([
				'success' => true,
				'html'    => '<p>Hello</p>',
				'subject' => 'Test Subject',
				'to'      => 'user@example.com',
			]);

		($this->action)($request, $response);
	}

	public function testReturnsPreviewHtmlWithSubjectToAndIframe(): void
	{
		$request = $this->createRequest([
			'mailerId'            => 'test-mailer',
			'bulkPreviewObjectId' => 'obj-1',
			'bulkCollection'      => 'subscribers',
		]);
		$response = $this->createResponse();

		$this->bulkMailerService->method('previewEmail')
			->willReturn([
				'success' => true,
				'html'    => '<p>Welcome John</p>',
				'subject' => 'Hello John',
				'to'      => 'john@example.com',
			]);

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('Subject:', $body);
		$this->assertStringContainsString('Hello John', $body);
		$this->assertStringContainsString('To:', $body);
		$this->assertStringContainsString('john@example.com', $body);
		$this->assertStringContainsString('iframe', $body);
		$this->assertStringContainsString('sandbox', $body);
	}

	public function testReturnsErrorHtmlOnServiceFailure(): void
	{
		$request = $this->createRequest([
			'mailerId'            => 'test-mailer',
			'bulkPreviewObjectId' => 'obj-1',
			'bulkCollection'      => 'subscribers',
		]);
		$response = $this->createResponse();

		$this->bulkMailerService->method('previewEmail')
			->willReturn([
				'success' => false,
				'message' => 'Preview error: Object not found',
			]);

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-error', $body);
		$this->assertStringContainsString('Object not found', $body);
	}

	public function testResponseHasHtmlContentType(): void
	{
		$request = $this->createRequest([
			'mailerId'            => 'test-mailer',
			'bulkPreviewObjectId' => 'obj-1',
			'bulkCollection'      => 'subscribers',
		]);
		$response = $this->createResponse();

		$this->bulkMailerService->method('previewEmail')
			->willReturn([
				'success' => false,
				'message' => 'Error',
			]);

		$result = ($this->action)($request, $response);

		$this->assertSame('text/html', $result->getHeaderLine('Content-Type'));
	}

	// ── Helpers ──

	/** @param array<string,mixed> $body */
	private function createRequest(array $body): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);

		return $request;
	}

	private function createResponse(): ResponseInterface
	{
		$stream = $this->createMock(StreamInterface::class);
		$buffer = '';

		$stream->method('write')->willReturnCallback(function (string $data) use (&$buffer): int {
			$buffer .= $data;

			return strlen($data);
		});

		$stream->method('__toString')->willReturnCallback(function () use (&$buffer): string {
			return $buffer;
		});

		$response = $this->createMock(ResponseInterface::class);
		$response->method('getBody')->willReturn($stream);
		$response->method('withHeader')->willReturnCallback(
			function (string $name, string $value) use ($response, &$buffer): ResponseInterface {
				$newResponse = $this->createMock(ResponseInterface::class);
				$stream      = $this->createMock(StreamInterface::class);

				$stream->method('write')->willReturnCallback(function (string $data) use (&$buffer): int {
					$buffer .= $data;

					return strlen($data);
				});
				$stream->method('__toString')->willReturnCallback(function () use (&$buffer): string {
					return $buffer;
				});

				$newResponse->method('getBody')->willReturn($stream);
				$newResponse->method('getHeaderLine')->willReturnCallback(
					function (string $headerName) use ($name, $value): string {
						return strtolower($headerName) === strtolower($name) ? $value : '';
					}
				);

				return $newResponse;
			}
		);

		return $response;
	}
}
