<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Twig\Service\BuilderAssetRenderer;
use TotalCMS\Domain\Twig\Service\BuilderNavigation;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for builder navigation and asset helpers.
 *
 * Accessed in Twig as `cms.builder.*`.
 */
class BuilderTwigAdapter
{
	/**
	 * Navigation and assets are their own services (src/Domain/Twig/Service);
	 * this class exposes them as `cms.builder.*` and keeps the page-URL
	 * helpers and the Stacks bridge, which need the page index directly.
	 */
	public function __construct(
		private readonly BuilderConfigService $builderConfig,
		private readonly IndexReader $indexReader,
		private readonly Config $config,
		private readonly BuilderNavigation $navigation,
		private readonly BuilderAssetRenderer $assets,
	) {
	}

	/**
	 * Get top-level navigation pages (no parent).
	 *
	 * Returns published, nav-visible pages in their stored order.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function nav(?string $collection = null): array
	{
		return $this->navigation->nav($collection);
	}

	/**
	 * Get child navigation pages for a specific parent.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function subnav(string $parentId, ?string $collection = null): array
	{
		return $this->navigation->subnav($parentId, $collection);
	}

	/**
	 * Get the navigation tree (published, nav-visible pages) with children
	 * nested under each parent. Each page node carries its full record plus
	 * a `children` array.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function navTree(?string $collection = null): array
	{
		return $this->navigation->navTree($collection);
	}

	/**
	 * Get every page as a nested tree — no draft/nav filtering. Intended for
	 * the admin sidebar where every page must be visible and editable.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function pagesTree(?string $collection = null): array
	{
		return $this->navigation->pagesTree($collection);
	}

	/**
	 * Reverse-route a builder page by ID, filling in dynamic `{param}` segments.
	 *
	 *     {{ cms.builder.url('about') }}                       => /about
	 *     {{ cms.builder.url('blog-post', { id: 'hello' }) }}  => /blog/hello
	 *
	 * Returns an empty string if the page is missing or has no route. Unfilled
	 * placeholders are left in the URL so the broken reference is visible.
	 *
	 * @param array<string,mixed> $params
	 */
	public function url(string $pageId, array $params = [], ?string $collection = null): string
	{
		$collectionId = $collection ?? $this->builderConfig->getPagesCollectionId();

		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Exception) {
			return '';
		}

		foreach ($index->objects as $page) {
			if ((string)($page['id'] ?? '') !== $pageId) {
				continue;
			}

			$route = (string)($page['route'] ?? '');
			if ($route === '') {
				return '';
			}

			$filled = preg_replace_callback(
				'/\{(\w+)\}/',
				fn (array $m): string => isset($params[$m[1]]) ? rawurlencode((string)$params[$m[1]]) : $m[0],
				$route,
			);

			return $this->config->api . ($filled ?? $route);
		}

		return '';
	}

	/**
	 * Get the canonical (absolute) URL for a builder page.
	 *
	 * The page counterpart to cms.collection.canonicalObjectUrl(). url()
	 * returns a site-relative path, but canonical tags, og:url and redirects
	 * all need scheme and host — and assembling those in a layout means
	 * hardcoding a domain, which is wrong everywhere the site is not
	 * production and goes stale silently.
	 *
	 *     {{ cms.builder.canonicalUrl('pricing') }}
	 *     {{ cms.builder.canonicalUrl(page) }}
	 *     {{ cms.builder.canonicalUrl('blog-post', { id: 'hello' }) }}
	 *
	 * Takes a page id or a whole page array, mirroring canonicalObjectUrl's
	 * string|array, so a layout can hand over the `page` it already has
	 * instead of digging out an id.
	 *
	 * Returns an empty string wherever url() does — a missing page, or one
	 * with no route — so the gap stays visible rather than emitting a bare
	 * domain that looks like a working link.
	 *
	 * @param string|array<string,mixed> $pageOrId
	 * @param array<string,mixed> $params
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function canonicalUrl(string|array $pageOrId, array $params = [], ?string $collection = null): string
	{
		$pageId = is_array($pageOrId) ? (string)($pageOrId['id'] ?? '') : $pageOrId;

		if ($pageId === '') {
			return '';
		}

		$url = $this->url($pageId, $params, $collection);

		if ($url === '' || str_starts_with($url, 'http')) {
			return $url;
		}

		$scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';

		return $scheme . '://' . $this->config->domain . $url;
	}

	// -------------------------
	// Stacks coexistence
	// -------------------------

	/**
	 * Read a Stacks-published HTML file from docroot. Lets a Builder template
	 * embed an existing Stacks-rendered page or fragment, sells "incremental
	 * migration" instead of rewrite-or-stay.
	 *
	 *   {{ cms.builder.stacksPage('/about')|raw }}                full HTML
	 *   {{ cms.builder.stacksPage('/about', 'body')|raw }}        inner <body>
	 *   {{ cms.builder.stacksPage('/legacy/nav.html', 'nav')|raw }} first <nav>
	 *
	 * Resolution tries the path as-is, then with `.html`, then with `/index.html`.
	 * Path traversal is blocked; missing files return an empty string. The
	 * second argument extracts the inner content of the first matching tag.
	 */
	public function stacksPage(string $path, string $extract = ''): string
	{
		$relative = ltrim($path, '/');
		if (str_contains($relative, '..') || $relative === '') {
			return '';
		}

		$candidates = [
			$relative,
			$relative . '.html',
			rtrim($relative, '/') . '/index.html',
		];

		$contents = '';
		foreach ($candidates as $candidate) {
			$full = $this->config->docroot . '/' . $candidate;
			if (is_file($full)) {
				$contents = (string)file_get_contents($full);

				break;
			}
		}

		if ($contents === '' || $extract === '') {
			return $contents;
		}

		return $this->extractTagContent($contents, $extract);
	}

	/**
	 * Resolve an asset URL with cache busting.
	 *
	 * Checks the manifest first (for hashed filenames), then falls back to
	 * file mtime for cache busting, then returns the raw path.
	 */
	public function asset(string $path): string
	{
		return $this->assets->asset($path);
	}

	/**
	 * Output a <link rel="stylesheet"> tag for a CSS asset.
	 */
	public function css(string $path): string
	{
		return $this->assets->css($path);
	}

	/**
	 * Output a <script> tag for a JS asset.
	 *
	 * @param array<string,mixed> $options Options: module (bool) adds type="module"
	 */
	public function js(string $path, array $options = []): string
	{
		return $this->assets->js($path, $options);
	}

	/**
	 * Output a <link rel="preload"> tag for an asset.
	 *
	 * Auto-adds crossorigin attribute for fonts (required by browsers).
	 */
	public function preload(string $path, string $as): string
	{
		return $this->assets->preload($path, $as);
	}

	/**
	 * Extract the inner content of the first occurrence of $tagName from $html.
	 * Returns the original HTML if the tag isn't found.
	 */
	private function extractTagContent(string $html, string $tagName): string
	{
		$tag = preg_quote(strtolower($tagName), '/');
		// Find <tag ...> ... </tag> non-greedy, case-insensitive
		if (preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '\s*>/is', $html, $matches) === 1) {
			return $matches[1];
		}

		return $html;
	}
}
