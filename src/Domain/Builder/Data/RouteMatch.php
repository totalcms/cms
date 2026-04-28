<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class RouteMatch
{
	/**
	 * @param string              $template   Template path (e.g., "pages/about.twig")
	 * @param string              $layout     Layout name (e.g., "default")
	 * @param array<string,mixed> $pageData   Full page/object data for the template
	 * @param array<string,string> $params    Extracted URL parameters ({id} → value)
	 * @param string|null         $collection Collection ID if collection URL match
	 */
	public function __construct(
		public string $template,
		public string $layout,
		public array $pageData,
		public array $params = [],
		public ?string $collection = null,
	) {
	}
}
