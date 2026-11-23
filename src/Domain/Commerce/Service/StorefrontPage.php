<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Lets a store use one of its own Site Builder pages where the store would
 * otherwise show a built-in one: the order page, the pay page.
 *
 * The page's template is rendered with the page as `page`, as the page
 * router gives it, plus whatever the store hands over (`order`, `token`).
 */
final readonly class StorefrontPage
{
	public function __construct(
		private ObjectFetcher $objects,
		private TwigEngine $twig,
	) {
	}

	/**
	 * @param array<string,mixed> $vars
	 *
	 * @return string|null the rendered page, or null when no page is set, or it or its template is gone
	 */
	public function render(string $pageId, array $vars): ?string
	{
		$pages = BuilderConfigService::DEFAULT_COLLECTION_ID;
		if ($pageId === '' || !$this->objects->existsObject($pages, $pageId)) {
			return null;
		}

		$page     = $this->objects->fetchObject($pages, $pageId)->toArray();
		$template = $page['template'] ?? '';
		if (!is_string($template) || $template === '' || !$this->twig->templateExists($template)) {
			return null;
		}

		return $this->twig->render($template, $vars + ['page' => $page, 'params' => []]);
	}
}
