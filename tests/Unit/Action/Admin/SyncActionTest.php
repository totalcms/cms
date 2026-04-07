<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Admin\SyncAction;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Renderer\JsonRenderer;

final class SyncActionTest extends TestCase
{
	private SyncAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $settingsFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $syncService;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer        = $this->createMock(JsonRenderer::class);
		$this->settingsFetcher = $this->createMock(SettingsFetcher::class);
		$this->syncService     = $this->createMock(SyncService::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);

		$this->action = new SyncAction(
			$this->renderer,
			$this->settingsFetcher,
			$this->syncService,
		);

		// Default: renderer returns response for chaining
		$this->renderer->method('json')->willReturn($this->response);
		$this->response->method('withStatus')->willReturn($this->response);
	}

	public function testReturnsErrorWhenSyncNotConfigured(): void
	{
		$this->settingsFetcher->method('loadSection')->with('sync')->willReturn([]);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeFalse();
				expect($data['error'])->toContain('not configured');
				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['action' => 'push']);
	}

	public function testReturnsErrorWhenUrlEmpty(): void
	{
		$this->settingsFetcher->method('loadSection')->willReturn(['url' => '', 'key' => 'some-key']);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeFalse();
				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['action' => 'push']);
	}

	public function testPushDelegatesToSyncService(): void
	{
		$this->settingsFetcher->method('loadSection')->willReturn([
			'url' => 'https://production.example.com',
			'key' => 'api-key',
		]);

		$this->request->method('getParsedBody')->willReturn([]);

		$pushResult = [
			'success'   => true,
			'message'   => 'Push complete.',
			'schemas'   => 2,
			'templates' => 1,
		];

		$this->syncService->expects($this->once())
			->method('push')
			->with('https://production.example.com', 'api-key', null, null)
			->willReturn($pushResult);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $pushResult)
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['action' => 'push']);
	}

	public function testPullDelegatesToSyncService(): void
	{
		$this->settingsFetcher->method('loadSection')->willReturn([
			'url' => 'https://production.example.com',
			'key' => 'api-key',
		]);

		$this->request->method('getParsedBody')->willReturn([]);

		$pullResult = [
			'success'   => true,
			'message'   => 'Pull complete.',
			'schemas'   => 3,
			'templates' => 2,
		];

		$this->syncService->expects($this->once())
			->method('pull')
			->with('https://production.example.com', 'api-key', null, null)
			->willReturn($pullResult);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $pullResult)
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['action' => 'pull']);
	}

	public function testPassesSchemaAndTemplateFilters(): void
	{
		$this->settingsFetcher->method('loadSection')->willReturn([
			'url' => 'https://example.com',
			'key' => 'key',
		]);

		$this->request->method('getParsedBody')->willReturn([
			'schemas'   => ['products', 'invoice'],
			'templates' => ['blog-post'],
		]);

		$this->syncService->expects($this->once())
			->method('push')
			->with('https://example.com', 'key', ['products', 'invoice'], ['blog-post'])
			->willReturn(['success' => true, 'message' => 'Push complete.', 'schemas' => 2, 'templates' => 1]);

		($this->action)($this->request, $this->response, ['action' => 'push']);
	}

	public function testReturnsErrorForUnknownAction(): void
	{
		$this->settingsFetcher->method('loadSection')->willReturn([
			'url' => 'https://example.com',
			'key' => 'key',
		]);

		$this->request->method('getParsedBody')->willReturn([]);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeFalse();
				expect($data['error'])->toContain('Unknown sync action');
				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['action' => 'invalid']);
	}

	public function testReturns502OnSyncServiceFailure(): void
	{
		$this->settingsFetcher->method('loadSection')->willReturn([
			'url' => 'https://example.com',
			'key' => 'key',
		]);

		$this->request->method('getParsedBody')->willReturn([]);

		$this->syncService->method('push')
			->willThrowException(new \RuntimeException('Connection refused'));

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeFalse();
				expect($data['error'])->toContain('Connection refused');
				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['action' => 'push']);
	}
}
