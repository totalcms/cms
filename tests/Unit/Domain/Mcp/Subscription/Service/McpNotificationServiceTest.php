<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Subscription\Service;

use Mcp\Server\Protocol;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;
use TotalCMS\Domain\Mcp\Subscription\Service\McpNotificationService;
use TotalCMS\Domain\Mcp\Subscription\Service\SubscriptionIndex;

final class McpNotificationServiceTest extends TestCase
{
	/** @var MockObject&SubscriptionIndex */
	private MockObject $index;

	/** @var MockObject&SubscriptionManagerInterface */
	private MockObject $subscriptionManager;

	/** @var MockObject&Protocol */
	private MockObject $protocol;

	/** @var MockObject&SessionManagerInterface */
	private MockObject $sessionManager;

	private NotificationTestLogger $logger;

	private McpNotificationService $service;

	protected function setUp(): void
	{
		$this->index               = $this->createMock(SubscriptionIndex::class);
		$this->subscriptionManager = $this->createMock(SubscriptionManagerInterface::class);
		$this->protocol            = $this->createMock(Protocol::class);
		$this->sessionManager      = $this->createMock(SessionManagerInterface::class);
		$this->logger              = new NotificationTestLogger();

		$this->service = new McpNotificationService(
			$this->index,
			$this->subscriptionManager,
			$this->protocol,
			$this->sessionManager,
			$this->logger,
		);
	}

	// -------------------------------------------------------------------------
	// 1. Empty subscriber set → returns 0; no dispatch; no error log.
	// -------------------------------------------------------------------------

	public function testEmptySubscriberSetReturnsZeroAndDispatchesNothing(): void
	{
		$uri = 'tcms://blog/';

		$this->index
			->method('subscribersOf')
			->with($uri)
			->willReturn([]);

		$this->subscriptionManager->expects($this->never())->method('notifyResourceChanged');
		$this->sessionManager->expects($this->never())->method('createWithId');

		$result = $this->service->notifyResourceChanged($uri);

		$this->assertSame(0, $result);
		$this->assertEmpty($this->logger->errors, 'no error should be logged when there are no subscribers');
	}

	// -------------------------------------------------------------------------
	// 2. One subscriber → returns 1; notifyResourceChanged called with right URI.
	// -------------------------------------------------------------------------

	public function testSingleSubscriberReturnsOneAndCallsSubscriptionManager(): void
	{
		$uri       = 'tcms://blog/';
		$sessionId = Uuid::v7()->toRfc4122();
		$session   = $this->createMock(SessionInterface::class);

		$this->index
			->method('subscribersOf')
			->with($uri)
			->willReturn([$sessionId]);

		$this->sessionManager
			->expects($this->once())
			->method('createWithId')
			->willReturn($session);

		$this->subscriptionManager
			->expects($this->once())
			->method('notifyResourceChanged')
			->with($this->protocol, $session, $uri);

		$result = $this->service->notifyResourceChanged($uri);

		$this->assertSame(1, $result);
		$this->assertEmpty($this->logger->errors);
	}

	// -------------------------------------------------------------------------
	// 3. Multiple subscribers → returns N; called N times.
	// -------------------------------------------------------------------------

	public function testMultipleSubscribersReturnsNAndCallsManagerNTimes(): void
	{
		$uri        = 'tcms://products/';
		$sessionIds = [
			Uuid::v7()->toRfc4122(),
			Uuid::v7()->toRfc4122(),
			Uuid::v7()->toRfc4122(),
		];

		$this->index
			->method('subscribersOf')
			->with($uri)
			->willReturn($sessionIds);

		$this->sessionManager
			->expects($this->exactly(3))
			->method('createWithId')
			->willReturn($this->createMock(SessionInterface::class));

		$this->subscriptionManager
			->expects($this->exactly(3))
			->method('notifyResourceChanged');

		$result = $this->service->notifyResourceChanged($uri);

		$this->assertSame(3, $result);
		$this->assertEmpty($this->logger->errors);
	}

	// -------------------------------------------------------------------------
	// 4. One subscriber's dispatch throws → others still notified, return = N-1,
	//    error logged with session_id + uri + exception context keys.
	// -------------------------------------------------------------------------

	public function testFailingSessionDoesNotBlockOtherSubscribersAndLogsError(): void
	{
		$uri            = 'tcms://gallery/';
		$failingId      = Uuid::v7()->toRfc4122();
		$succeedingId   = Uuid::v7()->toRfc4122();
		$succeedSession = $this->createMock(SessionInterface::class);
		$exception      = new \RuntimeException('stale session');

		$this->index
			->method('subscribersOf')
			->with($uri)
			->willReturn([$failingId, $succeedingId]);

		// First createWithId call (failing session) throws; second returns a good session.
		$this->sessionManager
			->expects($this->exactly(2))
			->method('createWithId')
			->willReturnCallback(function (Uuid $id) use ($failingId, $succeedSession, $exception): SessionInterface {
				if ($id->toRfc4122() === $failingId) {
					throw $exception;
				}

				return $succeedSession;
			});

		// Only the succeeding session calls notifyResourceChanged.
		$this->subscriptionManager
			->expects($this->once())
			->method('notifyResourceChanged')
			->with($this->protocol, $succeedSession, $uri);

		$result = $this->service->notifyResourceChanged($uri);

		$this->assertSame(1, $result);

		// One error must have been logged.
		$this->assertCount(1, $this->logger->errors);
		$context = $this->logger->errors[0]['context'];
		$this->assertSame($failingId, $context['session_id'], 'session_id context key must match the failing session');
		$this->assertSame($uri, $context['uri'], 'uri context key must match the URI being notified');
		$this->assertSame($exception, $context['exception'], 'exception context key must hold the thrown exception');
	}

	// -------------------------------------------------------------------------
	// 5. URI passed through is exactly what was given — no transformation.
	// -------------------------------------------------------------------------

	public function testUriIsPassedThroughUnmodified(): void
	{
		$uri       = 'tcms://my-collection/some/path?query=1';
		$sessionId = Uuid::v7()->toRfc4122();
		$session   = $this->createMock(SessionInterface::class);

		$this->index
			->method('subscribersOf')
			->with($uri)
			->willReturn([$sessionId]);

		$this->sessionManager
			->method('createWithId')
			->willReturn($session);

		$capturedUri = null;
		$this->subscriptionManager
			->expects($this->once())
			->method('notifyResourceChanged')
			->willReturnCallback(function (Protocol $p, SessionInterface $s, string $u) use (&$capturedUri): void {
				$capturedUri = $u;
			});

		$this->service->notifyResourceChanged($uri);

		$this->assertSame($uri, $capturedUri);
	}
}

/**
 * Minimal PSR-3 logger that records error-level calls for assertion.
 */
final class NotificationTestLogger extends AbstractLogger
{
	/** @var list<array{level: string, message: string, context: array<string,mixed>}> */
	public array $errors = [];

	/**
	 * @param array<string,mixed> $context
	 * @param mixed $level
	 */
	public function log($level, \Stringable|string $message, array $context = []): void
	{
		if ((string)$level === 'error') {
			$this->errors[] = ['level' => 'error', 'message' => (string)$message, 'context' => $context];
		}
	}
}
