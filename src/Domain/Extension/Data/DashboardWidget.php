<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Represents a dashboard widget added by an extension.
 */
final readonly class DashboardWidget
{
	public function __construct(
		public string $id,
		public string $label,
		public string $template,
		public string $position = 'main',
		public int $priority = 50,
	) {
	}
}
