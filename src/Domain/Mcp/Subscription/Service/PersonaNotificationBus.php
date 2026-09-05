<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;

/**
 * Restricts a notification bus to the resources one persona can actually read.
 *
 * **Why this exists.** In the handshake era a client subscribes with
 * `resources/subscribe`, and the SDK's ResourceSubscribeHandler calls
 * `$registry->getResource($uri)` before accepting it. Our registry is
 * persona-filtered — McpServerFactory only registers what
 * `ResourceRegistry::forPersona()` returns — so that lookup is the
 * authorization check, and a public persona simply cannot subscribe to a
 * private collection.
 *
 * The modern (2026-07-28) era removed `resources/subscribe` entirely. Its
 * replacement, `subscriptions/listen`, takes the URIs it wants as a
 * `notifications.resourceSubscriptions` array of plain strings.
 * `NotificationFilter::fromParams()` accepts them as-is, `intersect()` checks
 * only the server's global `resourcesSubscribe` capability, and `carries()` is
 * `in_array($notification->uri, $this->resourceSubscriptions)`. At no point does
 * the SDK ask the registry whether this caller may see that URI.
 *
 * Without this decorator, an anonymous caller could open a listen stream naming
 * `tcms://private-collection/` and be told every time it changed — leaking write
 * activity, timing and existence for content their persona cannot read.
 *
 * **Where the gate sits.** On the read side, not the publish side. Publishing
 * happens in whichever request wrote the object; it has no subscriber and no
 * persona in scope. Reading happens inside the listen stream, which was built
 * for exactly one persona — so that is where the question "may you see this?"
 * can be answered.
 *
 * Membership is exact. Registered resource URIs are concrete
 * (`tcms://{collection}/`, `tcms://view/{id}`) and are the same strings the
 * event listener publishes, so a prefix or template match would only widen the
 * gate — `tcms://blog/` must not authorise `tcms://blog-private/`.
 *
 * Notifications that are not URI-scoped (tools/prompts/resources list-changed)
 * pass through: they carry no content, and the listing the client re-reads in
 * response is itself persona-filtered.
 */
final class PersonaNotificationBus implements NotificationBusInterface
{
	/** @var array<string,true> Visible URIs as a set, for O(1) membership. */
	private array $visible;

	/**
	 * @param list<string> $visibleUris resource URIs this persona may read —
	 *                                  pass exactly what was registered for it
	 */
	public function __construct(
		private readonly NotificationBusInterface $inner,
		array $visibleUris,
	) {
		$this->visible = array_fill_keys($visibleUris, true);
	}

	public function publish(Notification $notification): void
	{
		$this->inner->publish($notification);
	}

	public function cursor(): int
	{
		return $this->inner->cursor();
	}

	public function since(int $cursor): array
	{
		[$notifications, $next] = $this->inner->since($cursor);

		$allowed = [];
		foreach ($notifications as $notification) {
			if ($this->maySee($notification)) {
				$allowed[] = $notification;
			}
		}

		// The cursor still advances past a withheld notification — the stream
		// must not re-read it forever hoping for a different answer.
		return [$allowed, $next];
	}

	private function maySee(Notification $notification): bool
	{
		if (!$notification instanceof ResourceUpdatedNotification) {
			return true;
		}

		return isset($this->visible[$notification->uri]);
	}
}
