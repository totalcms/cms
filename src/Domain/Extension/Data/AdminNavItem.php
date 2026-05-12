<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Represents a navigation item added by an extension to the admin sidebar.
 *
 * Pass raw SVG markup as the icon — it will be URL-encoded in the template:
 *   new AdminNavItem(label: 'My Ext', icon: '<svg viewBox="0 0 32 32">...</svg>')
 *
 * Leave empty to use the default puzzle piece icon.
 */
final readonly class AdminNavItem
{
	public function __construct(
		public string $label,
		public string $icon = '',
		public string $url = '',
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
