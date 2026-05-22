<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Mcp\Subscription\Service\ResourceNotifier;

/**
 * Bridges T3's `object.*` events to the MCP subscription surface.
 *
 * On every `object.created` / `object.updated` / `object.deleted` event, the
 * listener computes the affected resource URI (currently `tcms://{collection}/`
 * for the collection-level subscription model — per-object subscriptions are
 * deferred per the Phase 2 spec) and asks McpNotificationService to fan out
 * `notifications/resources/updated` to every subscribed session.
 *
 * **Coalescing.** Within one PHP request the listener keeps a small in-memory
 * map of `(uri => lastNotifyTimestamp)`. Duplicate events for the same URI
 * within COALESCE_WINDOW_SECONDS collapse to a single notification — sweeps
 * that fire many `object.updated` events on the same collection inside one
 * request don't produce a notification storm. Cross-request coalescing is
 * out of scope; if a separate request triggers another change on the same
 * URI one second later, both subscribers will be notified again.
 *
 * **Import suspension** is handled by EventDispatcher itself — during a bulk
 * import the dispatcher suppresses `object.*` events for the collection and
 * fires `import.created` / `import.updated` instead, which this listener does
 * NOT subscribe to. So a JumpStart import of 10k posts produces zero
 * subscription notifications, as intended.
 */
final class McpResourceSubscriptionListener
{
	private const COALESCE_WINDOW_SECONDS = 1;

	/** @var array<string,int> uri => last-notify unix timestamp */
	private array $lastNotified = [];

	public function __construct(
		private readonly ResourceNotifier $notifier,
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function onObjectCreated(array $payload): void
	{
		$this->dispatch($payload);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function onObjectUpdated(array $payload): void
	{
		$this->dispatch($payload);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function onObjectDeleted(array $payload): void
	{
		$this->dispatch($payload);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function dispatch(array $payload): void
	{
		$uri = $this->resolveUri($payload);
		if ($uri === null) {
			return;
		}

		$now = time();
		if (isset($this->lastNotified[$uri]) && ($now - $this->lastNotified[$uri]) < self::COALESCE_WINDOW_SECONDS) {
			return;
		}

		$this->lastNotified[$uri] = $now;
		$this->notifier->notifyResourceChanged($uri);
	}

	/**
	 * Map an object event payload to the resource URI that subscribers watch.
	 *
	 * Returns null when the payload doesn't carry a usable collection name —
	 * defensive guard for any future event source that emits an object.* event
	 * without one.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function resolveUri(array $payload): ?string
	{
		$collection = (string)($payload['collection'] ?? '');
		if ($collection === '') {
			return null;
		}

		return \sprintf('tcms://%s/', $collection);
	}
}
