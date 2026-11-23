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
 * with {@see isExtension()}. `badge` is a count shown beside the icon, 0 for none.
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
		public int $badge = 0,
	) {
	}

	/** The same entry with a count beside its icon: how many things on that page need someone. */
	public function withBadge(int $count): self
	{
		return new self($this->id, $this->label, $this->url, $this->permission, $this->edition, $this->icon, $this->keywords, $this->extensionId, max(0, $count));
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
