<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Builder\Data\RouteMatch;

/**
 * The page being previewed in-process (Share Cards), so cms.builder.page()
 * answers with it rather than matching the request path: the router skips
 * drafts, and a preview has to see them. Set only for the length of one
 * render; outside a preview it holds nothing and changes nothing.
 */
final class PagePreview
{
	private ?RouteMatch $match = null;

	public function show(RouteMatch $match): void
	{
		$this->match = $match;
	}

	public function clear(): void
	{
		$this->match = null;
	}

	public function current(): ?RouteMatch
	{
		return $this->match;
	}
}
