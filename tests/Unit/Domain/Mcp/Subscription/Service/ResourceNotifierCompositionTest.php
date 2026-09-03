<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Subscription\Service;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Subscription\Service\BusResourceNotifier;
use TotalCMS\Domain\Mcp\Subscription\Service\CompositeResourceNotifier;
use TotalCMS\Domain\Mcp\Subscription\Service\ResourceNotifier;

final class ResourceNotifierCompositionTest extends TestCase
{
	private function recordingBus(): NotificationBusInterface
	{
		return new class implements NotificationBusInterface {
			/** @var list<Notification> */
			public array $published = [];

			public function publish(Notification $notification): void
			{
				$this->published[] = $notification;
			}

			public function cursor(): int
			{
				return 0;
			}

			public function since(int $cursor): array
			{
				return [[], 0];
			}
		};
	}

	private function throwingBus(): NotificationBusInterface
	{
		return new class implements NotificationBusInterface {
			public function publish(Notification $notification): void
			{
				throw new \RuntimeException('bus is down');
			}

			public function cursor(): int
			{
				return 0;
			}

			public function since(int $cursor): array
			{
				return [[], 0];
			}
		};
	}

	/**
	 * @param int|\Throwable $result
	 */
	private function notifier(int|\Throwable $result): ResourceNotifier
	{
		return new class($result) implements ResourceNotifier {
			public int $calls = 0;

			public function __construct(private readonly int|\Throwable $result)
			{
			}

			public function notifyResourceChanged(string $uri): int
			{
				$this->calls++;

				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}

	// -------------------------------------------------------------------------
	// BusResourceNotifier
	// -------------------------------------------------------------------------

	public function testPublishesAResourceUpdatedNotification(): void
	{
		$bus = $this->recordingBus();

		$sent = (new BusResourceNotifier($bus))->notifyResourceChanged('tcms://blog/');

		$this->assertSame(1, $sent);
		$this->assertCount(1, $bus->published);
		$this->assertInstanceOf(ResourceUpdatedNotification::class, $bus->published[0]);
		$this->assertSame('tcms://blog/', $bus->published[0]->uri);
	}

	public function testABrokenBusDoesNotThrowIntoTheObjectSave(): void
	{
		// This runs inside ObjectSaver's event dispatch. A full disk must cost a
		// notification, never the write.
		$sent = (new BusResourceNotifier($this->throwingBus()))->notifyResourceChanged('tcms://blog/');

		$this->assertSame(0, $sent);
	}

	// -------------------------------------------------------------------------
	// CompositeResourceNotifier
	// -------------------------------------------------------------------------

	public function testEveryNotifierSeesTheChange(): void
	{
		$session = $this->notifier(3);
		$bus     = $this->notifier(1);

		$total = (new CompositeResourceNotifier(new \Psr\Log\NullLogger(), $session, $bus))
			->notifyResourceChanged('tcms://blog/');

		$this->assertSame(1, $session->calls);
		$this->assertSame(1, $bus->calls);
		$this->assertSame(4, $total);
	}

	public function testOneFailingEraDoesNotStarveTheOther(): void
	{
		$broken  = $this->notifier(new \RuntimeException('era storage is down'));
		$working = $this->notifier(2);

		$composite = new CompositeResourceNotifier(new \Psr\Log\NullLogger(), $broken, $working);

		$total = $composite->notifyResourceChanged('tcms://blog/');

		$this->assertSame(1, $working->calls, 'The working era must still be notified.');
		$this->assertSame(2, $total, 'The failure contributes nothing but does not abort.');
	}

	public function testNoNotifiersIsHarmless(): void
	{
		$this->assertSame(0, (new CompositeResourceNotifier())->notifyResourceChanged('tcms://blog/'));
	}
}
