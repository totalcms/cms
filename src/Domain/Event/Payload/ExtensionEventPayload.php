<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for extension.enabled and extension.disabled events.
 */
readonly class ExtensionEventPayload extends EventPayload
{
	public function __construct(
		public string $id,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
		];
	}
}
