<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for schema.saved and schema.deleted events.
 */
readonly class SchemaEventPayload extends EventPayload
{
	public function __construct(
		public string $schema,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'schema' => $this->schema,
		];
	}
}
