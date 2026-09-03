<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Publishes resource changes for the modern (2026-07-28) protocol era.
 *
 * The handshake-era sibling, McpNotificationService, resolves a URI to its
 * subscribed sessions and pushes into each one's outgoing queue. The modern era
 * inverts that: nobody tracks subscribers, every open `subscriptions/listen`
 * stream reads forward from a shared log and filters for itself. So this
 * notifier does not fan out — it appends once and returns.
 *
 * Authorization is not this class's job and deliberately cannot be: publishing
 * happens in whichever request wrote the object, which has no subscriber and no
 * persona in scope. The gate is PersonaNotificationBus, on the read side.
 */
final readonly class BusResourceNotifier implements ResourceNotifier
{
	public function __construct(
		private NotificationBusInterface $bus,
		private LoggerInterface $logger = new NullLogger(),
	) {
	}

	/**
	 * Publish one resource change.
	 *
	 * The return value is the ResourceNotifier contract's "how many were
	 * notified". A broadcast bus has no subscriber list to count, so the unit
	 * here is the publish itself: 1 when the change reached the log, 0 when it
	 * did not. Callers use it for observability only.
	 */
	public function notifyResourceChanged(string $uri): int
	{
		try {
			$this->bus->publish(new ResourceUpdatedNotification($uri));

			return 1;
		} catch (\Throwable $e) {
			// A missed refresh must never fail the object save that triggered it.
			$this->logger->error('BusResourceNotifier publish failed', [
				'uri'       => $uri,
				'exception' => $e,
			]);

			return 0;
		}
	}
}
