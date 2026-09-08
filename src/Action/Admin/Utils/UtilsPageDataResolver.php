<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

/**
 * Maps a utility page name to the builder that owns its template variables.
 *
 * The map is passed in explicitly (see the container factory added in Task
 * 10) rather than discovered, so the set of pages a builder serves is
 * visible in one place. Pages with no builder — logs, cache-manager, the
 * server checker and the other HTMX-driven utilities — resolve to null and
 * render with the default payload only.
 */
final readonly class UtilsPageDataResolver
{
	/** @param array<string,UtilsPageData> $builders page name → builder */
	public function __construct(
		private array $builders,
	) {
	}

	public function for(string $page): ?UtilsPageData
	{
		return $this->builders[$page] ?? null;
	}
}
