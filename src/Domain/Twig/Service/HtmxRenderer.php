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
	 * @param string               $buttonLabel Button label for click triggers
	 * @param string               $buttonClass Additional CSS classes
	 * @param bool                 $transition  Enable View Transitions API
	 */
	public function buildInitialTrigger(
		string $baseUrl,
		array $queryParams,
		string $trigger     = 'revealed',
		string $buttonLabel = 'Load More',
		string $buttonClass = '',
		bool $transition = false,
	): string {
		$url   = $baseUrl . '?' . http_build_query($queryParams);
		$class = trim('cms-load-more ' . $buttonClass);
		$swap  = 'outerHTML' . ($transition ? ' transition:true' : '');

		$attributes = [
			'hx-get'     => $url,
			'hx-trigger' => $trigger,
			'hx-swap'    => $swap,
			'class'      => $class,
		];

		if ($trigger === 'click') {
			return HTMLUtils::element('button', htmlspecialchars($buttonLabel), $attributes);
		}

		return HTMLUtils::element('div', '', $attributes);
	}

	/**
	 * Build a standalone HTMX button for external load-more placement.
	 *
	 * The button uses hx-target + hx-swap="beforeend" to append items into
	 * a container identified by CSS selector, rather than self-replacing.
	 *
	 * @param string               $baseUrl     Full base URL (e.g. "/api/collections/blog/query")
	 * @param array<string,string> $queryParams Built query parameters (must include mode=append, buttonId, target)
	 * @param string               $buttonLabel Button text
	 * @param string               $buttonClass Additional CSS classes
	 * @param bool                 $transition  Enable View Transitions API
	 * @param bool                 $load        Add "load" to hx-trigger for auto-fetch on page load
	 */
	public function buildButton(
		string $baseUrl,
		array $queryParams,
		string $buttonLabel = 'Load More',
		string $buttonClass = '',
		bool $transition = false,
		bool $load = false,
	): string {
		$buttonId = $queryParams['buttonId'] ?? '';
		$target   = $queryParams['target'] ?? '';
		$url      = $baseUrl . '?' . http_build_query($queryParams);
		$class    = trim('cms-load-more ' . $buttonClass);
		$swap     = 'beforeend' . ($transition ? ' transition:true' : '');
		$trigger  = $load ? 'load, click' : 'click';

		$attributes = [
			'id'         => $buttonId,
			'hx-get'     => $url,
			'hx-target'  => $target,
			'hx-trigger' => $trigger,
			'hx-swap'    => $swap,
			'class'      => $class,
		];

		return HTMLUtils::element('button', htmlspecialchars($buttonLabel), $attributes);
	}

	/**
	 * Build an out-of-band swap element for updating the external load-more button.
	 *
	 * When more items exist: returns a full button with hx-swap-oob="true" and updated offset.
	 * When no more items: returns a delete element that removes the button from the DOM.
	 *
	 * @param string               $baseUrl Full base URL
	 * @param QueryResult          $result  Paginated query result
	 * @param array<string,string> $params  Current query parameters (carries forward all state)
	 */
	public function buildOobButton(string $baseUrl, QueryResult $result, array $params): string
	{
		$buttonId = $params['buttonId'] ?? '';

		if (!$result->hasMore()) {
			return '<button id="' . htmlspecialchars($buttonId) . '" hx-swap-oob="delete"></button>';
		}

		$queryParams = [
			'format'   => 'html',
			'template' => $params['template'] ?? '',
			'offset'   => (string)$result->nextOffset(),
			'limit'    => $params['limit'] ?? '20',
			'mode'     => 'append',
			'buttonId' => $buttonId,
			'target'   => $params['target'] ?? '',
		];

		// Carry forward optional params
		$optionalParams = ['sort', 'include', 'exclude', 'search', 'buttonLabel', 'buttonClass', 'transition'];
		foreach ($optionalParams as $key) {
			if (isset($params[$key]) && $params[$key] !== '') {
				$queryParams[$key] = $params[$key];
			}
		}

		$buttonLabel = $params['buttonLabel'] ?? 'Load More';
		$buttonClass = $params['buttonClass'] ?? '';
		$transition  = !empty($params['transition']);
		$url         = $baseUrl . '?' . http_build_query($queryParams);
		$class       = trim('cms-load-more ' . $buttonClass);
		$swap        = 'beforeend' . ($transition ? ' transition:true' : '');

		$attributes = [
			'id'           => $buttonId,
			'hx-swap-oob'  => 'true',
			'hx-get'       => $url,
			'hx-target'    => $params['target'] ?? '',
			'hx-trigger'   => 'click',
			'hx-swap'      => $swap,
			'class'        => $class,
		];

		return HTMLUtils::element('button', htmlspecialchars($buttonLabel), $attributes);
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
		$optionalParams = ['sort', 'include', 'exclude', 'search', 'trigger', 'buttonLabel', 'buttonClass', 'transition'];
		foreach ($optionalParams as $key) {
			if (isset($params[$key]) && $params[$key] !== '') {
				$queryParams[$key] = $params[$key];
			}
		}

		$trigger     = $params['trigger'] ?? 'revealed';
		$buttonLabel = $params['buttonLabel'] ?? 'Load More';
		$buttonClass = $params['buttonClass'] ?? '';
		$transition  = !empty($params['transition']);

		$url   = $baseUrl . '?' . http_build_query($queryParams);
		$swap  = 'outerHTML' . ($transition ? ' transition:true' : '');
		$class = trim('cms-load-more ' . $buttonClass);

		$attributes = [
			'hx-get'     => $url,
			'hx-trigger' => $trigger,
			'hx-swap'    => $swap,
			'class'      => $class,
		];

		if ($trigger === 'click') {
			return HTMLUtils::element('button', htmlspecialchars($buttonLabel), $attributes);
		}

		return HTMLUtils::element('div', '', $attributes);
	}
}
