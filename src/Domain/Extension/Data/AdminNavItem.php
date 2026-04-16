<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Represents a navigation item added by an extension to the admin sidebar.
 */
final readonly class AdminNavItem
{
	public function __construct(
		public string $label,
		public string $icon,
		public string $url,
		public string $permission = 'admin',
		public int $priority = 50,
	) {
	}

	/**
	 * Derive a URL slug for active-state matching in the template.
	 */
	public function slug(): string
	{
		return trim($this->url, '/');
	}
}
