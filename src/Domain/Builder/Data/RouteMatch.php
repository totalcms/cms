<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class RouteMatch
{
	/**
	 * @param string              $template   Template path (e.g., "pages/about.twig")
	 * @param array<string,mixed> $pageData   Full page/object data for the template
	 * @param array<string,string> $params    Extracted URL parameters ({id} → value)
	 * @param string|null         $collection Collection ID if collection URL match
	 * @param int                 $status     HTTP status code to apply to the rendered response
	 * @param string              $redirectTo Destination URL when status is 3xx; middleware sends Location header instead of rendering
	 */
	public function __construct(
		public string $template,
		public array $pageData,
		public array $params = [],
		public ?string $collection = null,
		public int $status = 200,
		public string $redirectTo = '',
	) {
	}
}
