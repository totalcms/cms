<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

/**
 * Fans out `notifications/resources/updated` to every session subscribed to a
 * resource URI. The McpResourceSubscriptionListener depends on this interface
 * so the listener's tests can mock the notifier without subclassing the
 * concrete `McpNotificationService` (which stays final).
 */
interface ResourceNotifier
{
	/**
	 * Notify every session subscribed to $uri that the resource changed.
	 *
	 * Returns the number of sessions successfully notified — useful for
	 * observability and tests. Per-session failures are caught and logged
	 * inside the implementation; this method does not throw.
	 */
	public function notifyResourceChanged(string $uri): int;
}
