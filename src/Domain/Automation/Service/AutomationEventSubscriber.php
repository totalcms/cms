<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Object\Data\ObjectData;

/**
 * Bridges the EventDispatcher to automations: when a core event fires, it finds
 * the enabled event-trigger automations that match and enqueues an async run
 * for each. Registered once per core event at boot (see config/container.php
 * EventDispatcher factory).
 *
 * Runs reactively and async — the originating action is never blocked or
 * aborted by an automation.
 */
final readonly class AutomationEventSubscriber
{
	public function __construct(
		private AutomationResolver $resolver,
		private AutomationQueue $queue,
	) {
	}

	/**
	 * @param array<string,mixed> $payload the dispatcher's array payload
	 */
	public function handle(string $event, array $payload): void
	{
		$collection = (string)($payload['collection'] ?? '');
		$snapshot   = $this->snapshot($payload);

		foreach ($this->resolver->eventTriggers($event, $collection) as $match) {
			$this->queue->enqueue($match['id'], $match['trigger'], [], $snapshot);
		}
	}

	/**
	 * Snapshot the payload into plain arrays so it survives serialization onto
	 * the queue. The handler sees the object's state at event time — which is
	 * what lets `object.deleted` data and `*.updated` `previous` survive to the
	 * next tick (they are not re-fetched).
	 *
	 * @param array<string,mixed> $payload
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(array $payload): array
	{
		$out = [];
		foreach ($payload as $key => $value) {
			$out[$key] = $value instanceof ObjectData ? $value->toArray() : $value;
		}

		return $out;
	}
}
