<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Nav;

/**
 * One entry in the admin sidebar rail — a core page or an extension's item.
 *
 * `id` is what the `dashboard.moreMenu` setting stores: the page slug for a
 * core entry (`collections`), `ext:{extensionId}:{slug}` for an extension's.
 * `icon` is a CSS value for a core entry (`var(--icon-collections)`) and the
 * raw SVG an extension registered for its own; the template tells them apart
 * with {@see isExtension()}.
 */
final readonly class NavEntry
{
	public function __construct(
		public string $id,
		public string $label,
		public string $url,
		public string $permission,
		public ?string $edition = null,
		public string $icon = '',
		public string $keywords = 'nav',
		public string $extensionId = '',
	) {
	}

	public function isExtension(): bool
	{
		return $this->extensionId !== '';
	}

	/** URL without surrounding slashes, for active-state matching. */
	public function slug(): string
	{
		return trim($this->url, '/');
	}
}
