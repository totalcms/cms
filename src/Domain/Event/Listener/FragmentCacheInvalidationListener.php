<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Cache\FragmentCache;

/**
 * Busts {% cache %} fragments when their tagged collection changes. Bumps the
 * collection's generational version counter; fragments keyed on the old
 * version become unreachable and expire by TTL.
 *
 * Subscribes to object.created/updated/deleted and the import.created/updated
 * events that replace them during an import. Listeners receive the event
 * payload as an array (EventDispatcher::dispatch calls $payload->toArray()).
 */
final class FragmentCacheInvalidationListener
{
	public function __construct(
		private readonly FragmentCache $fragmentCache,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function onObjectChanged(array $payload): void
	{
		$collection = (string) ($payload['collection'] ?? '');
		if ($collection === '') {
			return;
		}

		$this->fragmentCache->bumpTag($collection);
	}
}
