<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for system-level events: devmode.enabled, devmode.disabled, cache.cleared.
 */
readonly class SystemEventPayload extends EventPayload
{
	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(
		private array $data = [],
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return $this->data;
	}
}
