<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Subscription\Service;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use TotalCMS\Domain\Mcp\Subscription\Service\SubscriptionIndex;
use TotalCMS\Domain\Mcp\Subscription\Service\McpSubscriptionManager;

final class McpSubscriptionManagerTest extends TestCase
{
	private string $indexFile;
	private SubscriptionIndex $index;
	private McpSubscriptionManager $manager;

	/** @var MockObject&SessionInterface */
	private MockObject $session;

	private Uuid $sessionUuid;
	private string $sessionId;

	protected function setUp(): void
	{
		$this->indexFile   = sys_get_temp_dir() . '/tcms_sub_mgr_test_' . uniqid('', true) . '.json';
		$this->index       = new SubscriptionIndex($this->indexFile);
		$this->manager     = new McpSubscriptionManager($this->index);

		$this->sessionUuid = Uuid::v7();
		$this->sessionId   = $this->sessionUuid->toRfc4122();

		$this->session = $this->createMock(SessionInterface::class);
		$this->session->method('getId')->willReturn($this->sessionUuid);
	}

	protected function tearDown(): void
	{
		foreach ([$this->indexFile, $this->indexFile . '.lock', $this->indexFile . '.tmp'] as $f) {
			if (file_exists($f)) {
				unlink($f);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// 1. subscribe() calls session->set() with the URI added
	// -------------------------------------------------------------------------

	public function testSubscribeCallsSessionSetWithUriAdded(): void
	{
		$uri = 'tcms://blog/';

		$this->session->expects($this->once())
			->method('get')
			->with('resource_subscriptions', [])
			->willReturn([]);

		$this->session->expects($this->once())
			->method('set')
			->with('resource_subscriptions', [$uri => true]);

		$this->session->method('save')->willReturn(true);

		$this->manager->subscribe($this->session, $uri);
	}

	// -------------------------------------------------------------------------
	// 2. subscribe() calls session->save() exactly once
	// -------------------------------------------------------------------------

	public function testSubscribeCallsSessionSaveExactlyOnce(): void
	{
		$uri = 'tcms://blog/';

		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([]);

		$this->session->method('set');

		$this->session->expects($this->once())
			->method('save')
			->willReturn(true);

		$this->manager->subscribe($this->session, $uri);
	}

	// -------------------------------------------------------------------------
	// 3. subscribe() writes to SubscriptionIndex
	// -------------------------------------------------------------------------

	public function testSubscribeWritesToSubscriptionIndex(): void
	{
		$uri = 'tcms://blog/';

		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([]);
		$this->session->method('set');
		$this->session->method('save')->willReturn(true);

		$this->manager->subscribe($this->session, $uri);

		$this->assertContains($this->sessionId, $this->index->subscribersOf($uri));
	}

	// -------------------------------------------------------------------------
	// 4. unsubscribe() mirrors subscribe: session updated, save called, index updated
	// -------------------------------------------------------------------------

	public function testUnsubscribeCallsSessionSetWithUriRemoved(): void
	{
		$uri = 'tcms://blog/';

		$this->session->expects($this->once())
			->method('get')
			->with('resource_subscriptions', [])
			->willReturn([$uri => true, 'tcms://other/' => true]);

		$this->session->expects($this->once())
			->method('set')
			->with('resource_subscriptions', ['tcms://other/' => true]);

		$this->session->expects($this->once())
			->method('save')
			->willReturn(true);

		$this->manager->unsubscribe($this->session, $uri);
	}

	public function testUnsubscribeRemovesSessionFromIndex(): void
	{
		$uri = 'tcms://blog/';

		// Manually prime the index as if subscribe() had already been called.
		$this->index->add($uri, $this->sessionId);
		$this->assertContains($this->sessionId, $this->index->subscribersOf($uri));

		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([$uri => true]);
		$this->session->method('set');
		$this->session->method('save')->willReturn(true);

		$this->manager->unsubscribe($this->session, $uri);

		$this->assertNotContains($this->sessionId, $this->index->subscribersOf($uri));
	}

	// -------------------------------------------------------------------------
	// 5. isSubscribed() reads from session, NOT from the index
	// -------------------------------------------------------------------------

	public function testIsSubscribedReturnsTrueWhenSessionKnowsAboutUri(): void
	{
		$uri = 'tcms://blog/';

		// The session says "subscribed"…
		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([$uri => true]);

		// …but the index has NO knowledge of this URI.
		// (Nothing was added to $this->index)

		$this->assertTrue($this->manager->isSubscribed($this->session, $uri));
	}

	public function testIsSubscribedReturnsFalseWhenSessionDoesNotKnowAboutUri(): void
	{
		$uri = 'tcms://blog/';

		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([]);

		$this->assertFalse($this->manager->isSubscribed($this->session, $uri));
	}

	// -------------------------------------------------------------------------
	// 6. notifyResourceChanged() returns early when not subscribed (no protocol call)
	// -------------------------------------------------------------------------

	public function testNotifyResourceChangedReturnsEarlyWhenNotSubscribed(): void
	{
		$uri = 'tcms://blog/';

		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([]);

		/** @var MockObject&Protocol $protocol */
		$protocol = $this->createMock(Protocol::class);
		$protocol->expects($this->never())->method('sendNotification');

		$this->manager->notifyResourceChanged($protocol, $this->session, $uri);
	}

	// -------------------------------------------------------------------------
	// 7. notifyResourceChanged() calls protocol->sendNotification when subscribed
	// -------------------------------------------------------------------------

	public function testNotifyResourceChangedSendsNotificationWhenSubscribed(): void
	{
		$uri = 'tcms://blog/';

		$this->session->method('get')
			->with('resource_subscriptions', [])
			->willReturn([$uri => true]);

		/** @var MockObject&Protocol $protocol */
		$protocol = $this->createMock(Protocol::class);
		$protocol->expects($this->once())
			->method('sendNotification')
			->with(
				$this->callback(static function (mixed $notification) use ($uri): bool {
					return $notification instanceof ResourceUpdatedNotification
						&& $notification->uri === $uri;
				}),
				$this->session
			);

		$this->manager->notifyResourceChanged($protocol, $this->session, $uri);
	}

	// -------------------------------------------------------------------------
	// 8. Failure-mode: index write failure is logged but does not propagate
	// -------------------------------------------------------------------------

	public function testSubscribeLogsAndSwallowsIndexWriteFailure(): void
	{
		// If the index write fails (e.g. filesystem full, permissions) the
		// session is still canonically subscribed via the SDK's per-session
		// storage. The cross-session index can be missing this entry, but the
		// failure must be logged loudly rather than swallowed silently or
		// allowed to propagate and break the SDK's subscribe handler.
		$failingIndex = $this->createMock(SubscriptionIndex::class);
		$failingIndex->expects($this->once())
			->method('add')
			->willThrowException(new \RuntimeException('disk full'));

		$logger  = new TestRecordingLogger();
		$manager = new McpSubscriptionManager($failingIndex, $logger);

		$this->session->method('get')->willReturn([]);
		$this->session->expects($this->once())->method('set');
		$this->session->expects($this->once())->method('save');

		$manager->subscribe($this->session, 'tcms://blog/');

		$this->assertNotEmpty($logger->records, 'expected an error log when the index write fails');
		$this->assertSame('error', $logger->records[0]['level']);
		$this->assertStringContainsString('index write failed', $logger->records[0]['message']);
	}
}

/**
 * Minimal PSR-3 logger that records every call for assertion.
 */
final class TestRecordingLogger extends \Psr\Log\AbstractLogger
{
	/** @var list<array{level: string, message: string, context: array<string,mixed>}> */
	public array $records = [];

	/**
	 * @param array<string,mixed> $context
	 */
	public function log($level, \Stringable|string $message, array $context = []): void
	{
		$this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
	}
}
