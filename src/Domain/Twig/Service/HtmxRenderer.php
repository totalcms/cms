<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Builds HTMX trigger elements for paginated content loading.
 *
 * Generates the HTMX-attributed HTML elements that chain paginated
 * requests together. Does not handle template rendering itself.
 */
readonly class HtmxRenderer
{
	/**
	 * Build the HTMX trigger suffix for a rendered page.
	 *
	 * Returns an empty string if there are no more items.
	 *
	 * @param string               $baseUrl Full base URL (e.g. "/api/collections/blog/query")
	 * @param QueryResult          $result  Paginated query result
	 * @param array<string,string> $params  Original query params (carried forward for next page)
	 */
	public function buildNextPageTrigger(string $baseUrl, QueryResult $result, array $params): string
	{
		if (!$result->hasMore()) {
			return '';
		}

		return $this->buildTrigger($baseUrl, $params, $result->nextOffset());
	}

	/**
	 * Build an HTMX trigger element for the initial page load.
	 *
	 * Used by the Twig adapter to output the first trigger that starts
	 * the load-more chain (page 2+, since page 1 is server-rendered).
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param string               $baseUrl     Full base URL (e.g. "/api/collections/blog/query")
	 * @param array<string,string> $queryParams Built query parameters
	 * @param string               $trigger     Trigger type: "revealed" or "click"
	 * @param string               $label       Button label for click triggers
	 * @param string               $extraClass  Additional CSS classes
	 * @param bool                 $transition  Enable View Transitions API
	 */
	public function buildInitialTrigger(
		string $baseUrl,
		array $queryParams,
		string $trigger    = 'revealed',
		string $label      = 'Load More',
		string $extraClass = '',
		bool $transition = false,
	): string {
		$url   = $baseUrl . '?' . http_build_query($queryParams);
		$class = trim('cms-load-more ' . $extraClass);
		$swap  = 'outerHTML' . ($transition ? ' transition:true' : '');

		$attributes = [
			'hx-get'     => $url,
			'hx-trigger' => $trigger,
			'hx-swap'    => $swap,
			'class'      => $class,
		];

		if ($trigger === 'click') {
			return HTMLUtils::element('button', htmlspecialchars($label), $attributes);
		}

		return HTMLUtils::element('div', '', $attributes);
	}

	/**
	 * Build an HTMX trigger element for loading the next page.
	 *
	 * @param string               $baseUrl    Full base URL
	 * @param array<string,string> $params     Current query parameters
	 * @param int                  $nextOffset Offset for the next page
	 */
	private function buildTrigger(string $baseUrl, array $params, int $nextOffset): string
	{
		$queryParams = [
			'format'   => 'html',
			'template' => $params['template'] ?? '',
			'offset'   => (string)$nextOffset,
			'limit'    => $params['limit'] ?? '20',
		];

		// Carry forward optional params
		$optionalParams = ['sort', 'include', 'exclude', 'search', 'trigger', 'label', 'transition'];
		foreach ($optionalParams as $key) {
			if (isset($params[$key]) && $params[$key] !== '') {
				$queryParams[$key] = $params[$key];
			}
		}

		$trigger    = $params['trigger'] ?? 'revealed';
		$label      = $params['label'] ?? 'Load More';
		$transition = !empty($params['transition']);

		$url  = $baseUrl . '?' . http_build_query($queryParams);
		$swap = 'outerHTML' . ($transition ? ' transition:true' : '');

		$attributes = [
			'hx-get'     => $url,
			'hx-trigger' => $trigger,
			'hx-swap'    => $swap,
			'class'      => 'cms-load-more',
		];

		if ($trigger === 'click') {
			return HTMLUtils::element('button', htmlspecialchars($label), $attributes);
		}

		return HTMLUtils::element('div', '', $attributes);
	}
}
