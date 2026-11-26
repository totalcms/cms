<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for user.login and user.logout events.
 */
readonly class UserEventPayload extends EventPayload
{
	/**
	 * @param string $collection the auth collection the user signed in to; '' when the caller does not know it
	 */
	public function __construct(
		public string $user,
		public string $collection = '',
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'user'       => $this->user,
			'collection' => $this->collection,
		];
	}
}
