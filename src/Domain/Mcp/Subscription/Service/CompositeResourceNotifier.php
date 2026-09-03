<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Feeds every protocol era from one resource change.
 *
 * T3 serves the handshake era and the modern (2026-07-28) era from the same
 * endpoint, and they deliver notifications by opposite mechanisms:
 * McpNotificationService pushes into each subscribed session's queue, while
 * BusResourceNotifier appends to a shared log that listen streams read.
 *
 * A change is one event either way, so McpResourceSubscriptionListener stays
 * unaware of both — it holds a ResourceNotifier and calls it once. This is the
 * seam that keeps it that way.
 *
 * One failing notifier must not stop the others: a client on one era should not
 * lose its refresh because the other era's storage is broken.
 */
final readonly class CompositeResourceNotifier implements ResourceNotifier
{
	/** @var list<ResourceNotifier> */
	private array $notifiers;

	public function __construct(
		private LoggerInterface $logger = new NullLogger(),
		ResourceNotifier ...$notifiers,
	) {
		$this->notifiers = array_values($notifiers);
	}

	/**
	 * Returns the total across every notifier — sessions pushed to, plus one
	 * per era that published to a broadcast bus.
	 */
	public function notifyResourceChanged(string $uri): int
	{
		$total = 0;

		foreach ($this->notifiers as $notifier) {
			try {
				$total += $notifier->notifyResourceChanged($uri);
			} catch (\Throwable $e) {
				$this->logger->error('A resource notifier failed', [
					'notifier'  => $notifier::class,
					'uri'       => $uri,
					'exception' => $e,
				]);
			}
		}

		return $total;
	}
}
