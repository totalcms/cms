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
	/**
	 * $extensionId is stamped by ExtensionManager when items are collected —
	 * extensions don't (and shouldn't) set it themselves. Templates use it
	 * to apply the access-group extension grant.
	 */
	public function __construct(
		public string $label,
		public string $icon = '',
		public string $url = '',
		public string $permission = 'admin',
		public int $priority = 50,
		public string $extensionId = '',
	) {
	}

	/**
	 * Copy of this item attributed to the extension that registered it.
	 */
	public function withExtensionId(string $extensionId): self
	{
		return new self($this->label, $this->icon, $this->url, $this->permission, $this->priority, $extensionId);
	}

	/**
	 * Derive a URL slug for active-state matching in the template.
	 */
	public function slug(): string
	{
		return trim($this->url, '/');
	}
}
