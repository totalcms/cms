<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Mailer\BulkMailerAction;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Renderer\RawRenderer;

final class BulkMailerActionTest extends TestCase
{
	private BulkMailerAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $bulkMailerService;
	private RawRenderer $renderer;

	protected function setUp(): void
	{
		$this->bulkMailerService = $this->createMock(BulkMailerService::class);
		$this->renderer          = new RawRenderer();

		$this->action = new BulkMailerAction(
			$this->bulkMailerService,
			$this->renderer,
		);
	}

	public function testReturnsErrorWhenMailerIdMissing(): void
	{
		$request  = $this->createRequest([]);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-error', $body);
		$this->assertStringContainsString('mailerId is required', $body);
	}

	public function testCallsServiceWithCorrectMappedFieldNames(): void
	{
		$request = $this->createRequest([
			'mailerId'         => 'test-mailer',
			'bulkscheduledAt'  => '2026-03-01 12:00:00',
			'bulkOverrideTo'   => 'override@example.com',
		]);
		$response = $this->createResponse();

		$this->bulkMailerService->expects($this->once())
			->method('queueBulkSend')
			->with('test-mailer', '2026-03-01 12:00:00', 'override@example.com')
			->willReturn([
				'success' => true,
				'batchId' => 'bulk_123',
				'count'   => 5,
				'message' => 'Queued 5 emails for sending',
			]);

		($this->action)($request, $response);
	}

	public function testReturnsSuccessHtmlWithBatchId(): void
	{
		$request  = $this->createRequest(['mailerId' => 'test-mailer']);
		$response = $this->createResponse();

		$this->bulkMailerService->method('queueBulkSend')
			->willReturn([
				'success' => true,
				'batchId' => 'bulk_abc',
				'count'   => 3,
				'message' => 'Queued 3 emails for sending',
			]);

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-success', $body);
		$this->assertStringContainsString('Queued!', $body);
		$this->assertStringContainsString('bulk_abc', $body);
	}

	public function testReturnsErrorHtmlOnServiceFailure(): void
	{
		$request  = $this->createRequest(['mailerId' => 'test-mailer']);
		$response = $this->createResponse();

		$this->bulkMailerService->method('queueBulkSend')
			->willReturn([
				'success' => false,
				'message' => 'Mailer template not found',
			]);

		$result = ($this->action)($request, $response);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('cms-error', $body);
		$this->assertStringContainsString('Mailer template not found', $body);
	}

	public function testResponseHasHtmlContentType(): void
	{
		$request  = $this->createRequest(['mailerId' => 'test-mailer']);
		$response = $this->createResponse();

		$this->bulkMailerService->method('queueBulkSend')
			->willReturn(['success' => false, 'message' => 'Error']);

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
			function (string $name, string $value) use (&$buffer): ResponseInterface {
				// Return a new mock that remembers the header
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
					fn (string $headerName): string => strtolower($headerName) === strtolower($name) ? $value : ''
				);

				return $newResponse;
			}
		);

		return $response;
	}
}
