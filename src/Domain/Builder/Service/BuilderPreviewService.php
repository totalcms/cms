<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Renders Site Builder template previews against a realistic page/object
 * context. The preview pane in the builder editor sends the user-edited
 * template content here along with an optional URL — the service routes the
 * URL through PageRouter to derive the right context (builder page vs.
 * collection-URL match), then renders the user's content with that context.
 *
 * Falls back to path-based context when no URL is supplied (e.g. the user
 * is previewing a layout/partial that has no URL of its own).
 */
readonly class BuilderPreviewService
{
	public function __construct(
		private TwigEngine $twigEngine,
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private PageRouter $pageRouter,
	) {
	}

	/**
	 * Render a preview for the given template path + content.
	 *
	 * Empty content returns an empty string so the iframe shows nothing
	 * rather than a Twig error.
	 *
	 * Errors are caught and rendered as a styled error box inside the
	 * preview frame — never thrown.
	 */
	public function render(string $path, string $content, string $previewUrl, string $pageId): string
	{
		if ($content === '') {
			return '';
		}

		try {
			if ($path !== '') {
				return $this->twigEngine->renderWithOverride(
					$path,
					$content,
					$this->buildContext($path, $pageId, $previewUrl),
				);
			}

			// Path-less preview (legacy/playground-style snippet) — fall back to string render
			return $this->twigEngine->renderString($content);
		} catch (\Throwable $e) {
			return sprintf(
				'<div class="cms-twig-error"><strong>Preview error:</strong><pre>%s</pre></div>',
				htmlspecialchars($e->getMessage()),
			);
		}
	}

	/**
	 * Build the render context for a preview.
	 *
	 * Priority:
	 *   1. If `previewUrl` is provided, run it through PageRouter — that gives
	 *      us a real `page` record + extracted `{param}` values + collection
	 *      context for any URL shape (builder pages, dynamic routes, collection
	 *      URL templates). The user-edited template content is then rendered
	 *      against that real context.
	 *   2. Otherwise, for `pages/*.twig` paths, auto-detect a matching builder-
	 *      page record so `page.*` is at least populated.
	 *   3. Otherwise (layouts/partials/macros/templates with no URL), render
	 *      with an empty page and empty params.
	 *
	 * @return array<string,mixed>
	 */
	private function buildContext(string $path, string $pageId, string $previewUrl): array
	{
		if ($previewUrl !== '') {
			$match = $this->pageRouter->match($previewUrl);
			if ($match !== null) {
				$data = ['params' => $match->params];
				// Same convention as PageRouterMiddleware: collection-URL
				// matches expose the record as `object`, builder-page matches
				// as `page`. Templates read whichever fits their context.
				if ($match->collection !== null) {
					$data['object'] = $match->pageData;
				} else {
					$data['page'] = $match->pageData;
				}

				return $data;
			}
			// Fall through if URL didn't match — rather than returning empty,
			// at least give the page-template path-based fallback below a chance.
		}

		if (!str_starts_with($path, 'pages/')) {
			return ['page' => (new PageData([]))->toArray(), 'params' => []];
		}

		$collectionId = $this->builderConfig->getPagesCollectionId();
		$pageRecord   = $this->resolvePageRecord($collectionId, $path, $pageId);

		return [
			'page'   => $pageRecord !== null ? (new PageData($pageRecord))->toArray() : (new PageData([]))->toArray(),
			'params' => [],
		];
	}

	/**
	 * Resolve a page record either by explicit ID (preferred — selected from
	 * the editor UI) or by scanning for the first page that uses this template.
	 *
	 * @return array<string,mixed>|null
	 */
	private function resolvePageRecord(string $collectionId, string $path, string $pageId): ?array
	{
		if ($pageId !== '') {
			try {
				return $this->objectFetcher->fetchObject($collectionId, $pageId)->toArray();
			} catch (\Throwable) {
				return null;
			}
		}

		// Path is e.g. "pages/about.twig" — strip prefix + extension to get template name
		$templateName = preg_replace('#^pages/|\\.twig$#', '', $path) ?? '';
		if ($templateName === '') {
			return null;
		}

		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Throwable) {
			return null;
		}

		foreach ($index->objects as $object) {
			if ((string)($object['template'] ?? '') === $templateName) {
				try {
					return $this->objectFetcher->fetchObject($collectionId, (string)$object['id'])->toArray();
				} catch (\Throwable) {
					return $object;
				}
			}
		}

		return null;
	}
}
