<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Represents a matched extension route.
 */
final readonly class ExtensionRoute
{
	public function __construct(
		public mixed $handler,
		public bool $public = false,
	) {
	}
}
