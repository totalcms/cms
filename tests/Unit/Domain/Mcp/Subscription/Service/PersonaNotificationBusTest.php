<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Subscription\Service;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Subscription\Service\PersonaNotificationBus;

/**
 * The modern (2026-07-28) era removed `resources/subscribe`, and with it the
 * only place the SDK checked a subscription URI against the registry.
 * `subscriptions/listen` takes `notifications.resourceSubscriptions` as raw
 * strings and filters with a bare in_array — no registry lookup anywhere.
 *
 * This class is what restores the check the handshake era gets for free from
 * ResourceSubscribeHandler's `$registry->getResource($uri)`. If these tests
 * fail, an anonymous caller can watch write activity on collections their
 * persona cannot read — do NOT relax them to make something else pass.
 */
final class PersonaNotificationBusTest extends TestCase
{
	/**
	 * @param list<Notification> $queued
	 */
	private function inner(array $queued = []): NotificationBusInterface
	{
		return new class($queued) implements NotificationBusInterface {
			/** @param list<Notification> $queued */
			public function __construct(private array $queued)
			{
			}

			/** @var list<Notification> */
			public array $published = [];

			public function publish(Notification $notification): void
			{
				$this->published[] = $notification;
			}

			public function cursor(): int
			{
				return 7;
			}

			public function since(int $cursor): array
			{
				return [$this->queued, 42];
			}
		};
	}

	// -------------------------------------------------------------------------
	// The gate
	// -------------------------------------------------------------------------

	public function testAVisibleResourceUpdateIsDelivered(): void
	{
		$bus = new PersonaNotificationBus(
			$this->inner([new ResourceUpdatedNotification('tcms://blog/')]),
			['tcms://blog/'],
		);

		[$found, $_] = $bus->since(0);

		$this->assertCount(1, $found);
		$this->assertSame('tcms://blog/', $found[0]->uri);
	}

	public function testAnInvisibleResourceUpdateIsWithheld(): void
	{
		$bus = new PersonaNotificationBus(
			$this->inner([new ResourceUpdatedNotification('tcms://private/')]),
			['tcms://blog/'],
		);

		[$found, $_] = $bus->since(0);

		$this->assertSame(
			[],
			$found,
			'A persona that cannot read a resource must not learn that it changed.',
		);
	}

	public function testAnEmptyVisibleSetWithholdsEverythingUriScoped(): void
	{
		// A persona with no readable resources at all — the anonymous case on a
		// site with no public collections. Fail closed, not open.
		$bus = new PersonaNotificationBus(
			$this->inner([new ResourceUpdatedNotification('tcms://blog/')]),
			[],
		);

		[$found, $_] = $bus->since(0);

		$this->assertSame([], $found);
	}

	public function testFilteringIsExactNotPrefixBased(): void
	{
		// tcms://blog/ must not authorise tcms://blog-private/. Registered URIs
		// are concrete, so membership is the whole test — a str_starts_with
		// here would be a real leak.
		$bus = new PersonaNotificationBus(
			$this->inner([
				new ResourceUpdatedNotification('tcms://blog-private/'),
				new ResourceUpdatedNotification('tcms://blog/extra'),
			]),
			['tcms://blog/'],
		);

		[$found, $_] = $bus->since(0);

		$this->assertSame([], $found);
	}

	public function testMixedBatchKeepsOnlyTheVisibleOnes(): void
	{
		$bus = new PersonaNotificationBus(
			$this->inner([
				new ResourceUpdatedNotification('tcms://blog/'),
				new ResourceUpdatedNotification('tcms://secret/'),
				new ResourceUpdatedNotification('tcms://view/sales'),
			]),
			['tcms://blog/', 'tcms://view/sales'],
		);

		[$found, $_] = $bus->since(0);

		$this->assertSame(['tcms://blog/', 'tcms://view/sales'], array_map(
			static fn (ResourceUpdatedNotification $n): string => $n->uri,
			$found,
		));
	}

	public function testNonResourceNotificationsPassThrough(): void
	{
		// Tool/prompt/resource list-changed notifications are not URI-scoped and
		// carry no content — the registry the client would then re-read is
		// already persona-filtered.
		$bus = new PersonaNotificationBus(
			$this->inner([new ToolListChangedNotification()]),
			[],
		);

		[$found, $_] = $bus->since(0);

		$this->assertCount(1, $found);
		$this->assertInstanceOf(ToolListChangedNotification::class, $found[0]);
	}

	// -------------------------------------------------------------------------
	// Delegation — the decorator must not change anything else
	// -------------------------------------------------------------------------

	public function testCursorAndNextCursorComeFromTheInnerBus(): void
	{
		$bus = new PersonaNotificationBus($this->inner(), ['tcms://blog/']);

		[$_, $next] = $bus->since(0);

		$this->assertSame(7, $bus->cursor());
		$this->assertSame(42, $next, 'A withheld notification must still advance the cursor.');
	}

	public function testPublishIsNotFiltered(): void
	{
		// Publishing happens in whichever request wrote the object and has no
		// subscriber context. The gate belongs on the read side only.
		$inner = $this->inner();
		$bus   = new PersonaNotificationBus($inner, []);

		$bus->publish(new ResourceUpdatedNotification('tcms://secret/'));

		$this->assertCount(1, $inner->published);
	}
}
