<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Base class for typed event payloads.
 * Subclasses define the specific shape for each event type.
 * EventDispatcher converts these to arrays before passing to listeners
 * for backwards compatibility with extensions.
 */
abstract readonly class EventPayload
{
	/** @return array<string,mixed> */
	abstract public function toArray(): array;
}
