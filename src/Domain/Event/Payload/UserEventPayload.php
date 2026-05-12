<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for user.login and user.logout events.
 */
readonly class UserEventPayload extends EventPayload
{
	public function __construct(
		public string $user,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'user' => $this->user,
		];
	}
}
