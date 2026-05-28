<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\Pushover;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Bundled\Pushover\PushoverService;
use TotalCMS\Bundled\Pushover\SendPushoverAction;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/pushover/PushoverService.php';
require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/pushover/SendPushoverAction.php';

final class SendPushoverActionTest extends TestCase
{
	private PushoverService&MockObject $pushoverService;
	private JsonRenderer&MockObject $renderer;
	private AccessManager&MockObject $accessManager;
	private CacheManager&MockObject $cache;
	private LoggerFactory&MockObject $loggerFactory;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->pushoverService = $this->createMock(PushoverService::class);
		$this->renderer        = $this->createMock(JsonRenderer::class);
		$this->accessManager   = $this->createMock(AccessManager::class);
		$this->cache           = $this->createMock(CacheManager::class);
		$this->psr17           = new Psr17Factory();

		$logger              = $this->createMock(LoggerInterface::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn($logger);
	}

	// -------------------------------------------------------------------------
	// Rate limit — per-IP
	// -------------------------------------------------------------------------

	public function testReturns429WhenIpLimitExceeded(): void
	{
		// Cache already holds 10 hits for this IP
		$this->cache->method('getData')->willReturn(10);

		$this->pushoverService->expects($this->never())->method('send');

		$rateLimitedResponse = $this->psr17->createResponse(429);
		$this->renderer->method('json')->willReturn($rateLimitedResponse);

		$action = $this->buildAction(rateLimitPerMinute: 10);

		$request  = $this->buildRequest(['message' => 'Hello'], '1.2.3.4');
		$response = $this->psr17->createResponse(200);

		$result = $action($request, $response);

		$this->assertSame(429, $result->getStatusCode());
	}

	public function testReturns429WhenUserLimitExceeded(): void
	{
		// IP counter is fine (0), but user counter is at limit
		$this->accessManager->method('userData')->willReturn(['id' => 'user-abc']);

		$callCount = 0;
		$this->cache->method('getData')->willReturnCallback(function () use (&$callCount): int {
			$callCount++;

			// First call = IP counter (ok), second call = user counter (at limit)
			return $callCount === 1 ? 0 : 10;
		});

		$this->pushoverService->expects($this->never())->method('send');

		$rateLimitedResponse = $this->psr17->createResponse(429);
		$this->renderer->method('json')->willReturn($rateLimitedResponse);

		$action = $this->buildAction(rateLimitPerMinute: 10);

		$request  = $this->buildRequest(['message' => 'Hello'], '1.2.3.4');
		$response = $this->psr17->createResponse(200);

		$result = $action($request, $response);

		$this->assertSame(429, $result->getStatusCode());
	}

	public function testPassesThroughWhenUnderLimit(): void
	{
		// Counters well below limit
		$this->cache->method('getData')->willReturn(2);

		$this->accessManager->method('userData')->willReturn(['id' => 'user-abc']);

		$this->pushoverService->expects($this->once())
			->method('send')
			->willReturn(OperationResult::success('Notification sent'));

		$okResponse = $this->psr17->createResponse(200);
		$this->renderer->method('json')->willReturn($okResponse);

		$action = $this->buildAction(rateLimitPerMinute: 10);

		$request  = $this->buildRequest(['message' => 'Hello'], '1.2.3.4');
		$response = $this->psr17->createResponse(200);

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
	}

	public function testRateLimiterDisabledWhenSetToZero(): void
	{
		// Cache would normally block but limiter is disabled
		$this->cache->method('getData')->willReturn(9999);

		$this->pushoverService->expects($this->once())
			->method('send')
			->willReturn(OperationResult::success('Notification sent'));

		$okResponse = $this->psr17->createResponse(200);
		$this->renderer->method('json')->willReturn($okResponse);

		$action = $this->buildAction(rateLimitPerMinute: 0);

		$request  = $this->buildRequest(['message' => 'Hello'], '1.2.3.4');
		$response = $this->psr17->createResponse(200);

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
	}

	public function testLogsWarningWhenRateLimitExceeded(): void
	{
		$this->cache->method('getData')->willReturn(10);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Pushover rate limit exceeded',
				$this->callback(fn ($ctx): bool => isset($ctx['ip']) && isset($ctx['limit']) && $ctx['limit'] === 10)
			);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($logger);

		$rateLimitedResponse = $this->psr17->createResponse(429);
		$this->renderer->method('json')->willReturn($rateLimitedResponse);

		$action = new SendPushoverAction(
			$this->pushoverService,
			$this->renderer,
			$this->accessManager,
			$this->cache,
			$loggerFactory,
			10,
		);

		$request  = $this->buildRequest(['message' => 'Hello'], '1.2.3.4');
		$response = $this->psr17->createResponse(200);

		$action($request, $response);
	}

	public function testCountersAreIncrementedOnSuccess(): void
	{
		// 3 stored hits, limit is 10 — should pass and then increment
		$this->cache->method('getData')->willReturn(3);

		$this->accessManager->method('userData')->willReturn(['id' => 'user-abc']);

		$this->pushoverService->method('send')
			->willReturn(OperationResult::success('Notification sent'));

		// Expect storeData called at least twice: once for IP, once for user
		$this->cache->expects($this->atLeast(2))->method('storeData');

		$okResponse = $this->psr17->createResponse(200);
		$this->renderer->method('json')->willReturn($okResponse);

		$action = $this->buildAction(rateLimitPerMinute: 10);

		$request = $this->buildRequest(['message' => 'Hello'], '5.6.7.8');
		$action($request, $this->psr17->createResponse(200));
	}

	public function testIpExtractedFromCloudflareHeader(): void
	{
		// Over limit so we get a fast-path 429 without touching the service
		$this->cache->method('getData')->willReturn(10);

		$rateLimitedResponse = $this->psr17->createResponse(429);
		$this->renderer->method('json')->willReturn($rateLimitedResponse);

		$action = $this->buildAction(rateLimitPerMinute: 10);

		// CF-Connecting-IP takes precedence
		$request = $this->psr17->createServerRequest('POST', '/ext/totalcms/pushover/send')
			->withHeader('CF-Connecting-IP', '203.0.113.42')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded');

		$result = $action($request, $this->psr17->createResponse(200));

		$this->assertSame(429, $result->getStatusCode());
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function buildAction(int $rateLimitPerMinute = 10): SendPushoverAction
	{
		return new SendPushoverAction(
			$this->pushoverService,
			$this->renderer,
			$this->accessManager,
			$this->cache,
			$this->loggerFactory,
			$rateLimitPerMinute,
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function buildRequest(array $body, string $remoteAddr = '127.0.0.1'): \Psr\Http\Message\ServerRequestInterface
	{
		return $this->psr17->createServerRequest('POST', '/ext/totalcms/pushover/send', ['REMOTE_ADDR' => $remoteAddr])
			->withParsedBody($body);
	}
}
