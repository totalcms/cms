<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Twig\Service\HtmxRenderer;

/**
 * Twig sub-adapter for frontend rendering helpers.
 *
 * Accessed in Twig as `cms.render.*`.
 * Provides methods that generate HTML output for frontend use.
 */
readonly class RenderTwigAdapter
{
	public function __construct(
		private HtmxRenderer $htmxRenderer,
	) {
	}

	/**
	 * Generate an HTMX trigger element for paginated content loading.
	 *
	 * The initial page is rendered server-side by the user's template.
	 * This outputs the HTMX element that triggers loading page 2+.
	 *
	 * Usage in Twig:
	 * ```twig
	 * {{ cms.render.loadMore('blog', {
	 *     template: 'blog/card',
	 *     limit: 10,
	 *     sort: 'date:desc',
	 *     include: 'published:true',
	 *     trigger: 'revealed'
	 * }) }}
	 * ```
	 *
	 * @param string              $collection Collection identifier
	 * @param array<string,mixed> $options    Options: template (required), limit, sort, include, exclude, search, trigger, label, class
	 */
	public function loadMore(string $collection, array $options = []): string
	{
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			return '<!-- cms.render.loadMore: "template" option is required -->';
		}

		$limit   = max(1, (int)($options['limit'] ?? 20));
		$trigger = (string)($options['trigger'] ?? 'revealed');
		$label   = (string)($options['label'] ?? 'Load More');

		// Build query params — offset starts at limit because page 1 is server-rendered
		$queryParams = [
			'format'   => 'html',
			'template' => $template,
			'offset'   => (string)$limit,
			'limit'    => (string)$limit,
		];

		// Add optional params
		$optionalKeys = ['sort', 'include', 'exclude', 'search'];
		foreach ($optionalKeys as $key) {
			if (isset($options[$key]) && (string)$options[$key] !== '') {
				$queryParams[$key] = (string)$options[$key];
			}
		}

		// Pass trigger and label through so the chain preserves them
		if ($trigger !== 'revealed') {
			$queryParams['trigger'] = $trigger;
		}
		if ($label !== 'Load More') {
			$queryParams['label'] = $label;
		}

		$transition = !empty($options['transition']);
		if ($transition) {
			$queryParams['transition'] = '1';
		}

		$extraClass = (string)($options['class'] ?? '');

		return $this->htmxRenderer->buildInitialTrigger($collection, $queryParams, $trigger, $label, $extraClass, $transition);
	}
}
