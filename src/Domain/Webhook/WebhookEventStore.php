<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Webhook;

use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;

/**
 * Remembers which webhook events were handled, so one that is sent again is
 * not handled again.
 *
 * A sender repeats an event when it gets no answer, and sometimes when it
 * does. Stripe and PayPal keep trying for three days; events are remembered
 * for seven, then forgotten.
 *
 * Two ways to use it:
 *
 * - {@see firstSeen()} checks and remembers in one locked step. Use it when
 *   handling an event twice would only waste work.
 * - {@see seen()} first, then {@see remember()} once the event was handled.
 *   Use it when the work must not be lost: if handling fails, the event is
 *   not remembered and the sender's retry gets another go.
 *
 * It lives in `.system/webhook-events.json`. A file that cannot be read
 * counts as empty: handling an event twice is safer than refusing them all.
 */
final readonly class WebhookEventStore
{
	public const RETENTION = 7 * 86400;

	private const FILE = '.system/webhook-events.json';

	/** A longer key is stored as a hash: an event id is short, and the file stays small. */
	private const MAX_KEY = 200;

	public function __construct(private AtomicJsonStore $store)
	{
	}

	/** @param string $key the sender and the event's id, such as `stripe:evt_123` */
	public function seen(string $key): bool
	{
		$key = $this->key($key);

		return $key !== '' && $this->fresh($this->store->load(self::FILE, CorruptPolicy::TreatAsEmpty)[$key] ?? null);
	}

	/** Remember an event that has now been handled. */
	public function remember(string $key): void
	{
		$key = $this->key($key);
		if ($key === '') {
			return;
		}

		$this->store->mutate(self::FILE, fn (array $events): array => $this->put($events, $key), CorruptPolicy::TreatAsEmpty, lock: true);
	}

	/**
	 * True the first time an event is seen, false every time after. The check
	 * and the remembering are one locked step, so two deliveries at the same
	 * moment cannot both be first.
	 */
	public function firstSeen(string $source, string $eventId): bool
	{
		$key = $this->key($source . ':' . $eventId);
		if ($key === '') {
			return false;
		}

		$first = false;
		$this->store->mutate(self::FILE, function (array $events) use ($key, &$first): array {
			if ($this->fresh($events[$key] ?? null)) {
				return $events;
			}
			$first = true;

			return $this->put($events, $key);
		}, CorruptPolicy::TreatAsEmpty, lock: true);

		return $first;
	}

	/**
	 * The events with this one added at the end, and the expired ones gone.
	 *
	 * @param array<string,mixed> $events
	 *
	 * @return array<string,int>
	 */
	private function put(array $events, string $key): array
	{
		unset($events[$key]);

		$kept = [];
		foreach ($events as $known => $at) {
			if (is_int($at) && $this->fresh($at)) {
				$kept[(string)$known] = $at;
			}
		}
		$kept[$key] = time();

		return $kept;
	}

	private function fresh(mixed $at): bool
	{
		return is_int($at) && $at > time() - self::RETENTION;
	}

	private function key(string $key): string
	{
		return strlen($key) > self::MAX_KEY ? 'sha256:' . hash('sha256', $key) : $key;
	}
}
