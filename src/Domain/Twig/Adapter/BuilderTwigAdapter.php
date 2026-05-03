<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for builder navigation and asset helpers.
 *
 * Accessed in Twig as `cms.builder.*`.
 */
class BuilderTwigAdapter
{
	/** @var array<string,array{file:string}>|false|null false = not loaded yet */
	private array|false|null $manifestCache = false;

	public function __construct(
		private readonly BuilderConfigService $builderConfig,
		private readonly IndexReader $indexReader,
		private readonly BuilderOrderService $orderService,
		private readonly Config $config,
	) {
	}

	// -------------------------
	// Navigation
	// -------------------------

	/**
	 * Get top-level navigation pages (no parent).
	 *
	 * Returns published, nav-visible pages in their stored order.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function nav(?string $collection = null): array
	{
		$tree = $this->navTree($collection);

		// Strip nested children — nav() returns flat top-level only
		return array_map(static function (array $node): array {
			unset($node['children']);

			return $node;
		}, $tree);
	}

	/**
	 * Get child navigation pages for a specific parent.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function subnav(string $parentId, ?string $collection = null): array
	{
		$node = $this->findNode($this->navTree($collection), $parentId);
		if ($node === null) {
			return [];
		}

		$children = $node['children'] ?? [];
		if (!is_array($children)) {
			return [];
		}

		return array_map(static function (array $child): array {
			unset($child['children']);

			return $child;
		}, $children);
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
		return $this->hydrateOrderTree($collection, true);
	}

	/**
	 * Get every page as a nested tree — no draft/nav filtering. Intended for
	 * the admin sidebar where every page must be visible and editable.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function pagesTree(?string $collection = null): array
	{
		return $this->hydrateOrderTree($collection, false);
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

	// -------------------------
	// Assets
	// -------------------------

	/**
	 * Resolve an asset URL with cache busting.
	 *
	 * Checks the manifest first (for hashed filenames), then falls back to
	 * file mtime for cache busting, then returns the raw path.
	 */
	public function asset(string $path): string
	{
		return $this->resolveAssetUrl($path);
	}

	/**
	 * Output a <link rel="stylesheet"> tag for a CSS asset.
	 */
	public function css(string $path): string
	{
		$url = $this->resolveAssetUrl($path);

		return '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">';
	}

	/**
	 * Output a <script> tag for a JS asset.
	 *
	 * @param array<string,mixed> $options Options: module (bool) adds type="module"
	 */
	public function js(string $path, array $options = []): string
	{
		$url  = $this->resolveAssetUrl($path);
		$type = empty($options['module']) ? '' : ' type="module"';

		return '<script' . $type . ' src="' . htmlspecialchars($url) . '"></script>';
	}

	/**
	 * Output a <link rel="preload"> tag for an asset.
	 *
	 * Auto-adds crossorigin attribute for fonts (required by browsers).
	 */
	public function preload(string $path, string $as): string
	{
		$url         = $this->resolveAssetUrl($path);
		$crossorigin = $as === 'font' ? ' crossorigin' : '';

		return '<link rel="preload" href="' . htmlspecialchars($url) . '" as="' . htmlspecialchars($as) . '"' . $crossorigin . '>';
	}

	// -------------------------
	// Private — Navigation
	// -------------------------

	/**
	 * Walk the order-file tree and attach each node's full page record from
	 * the index. When $publicOnly is true, drafts and nav-hidden pages are
	 * dropped (and any children of dropped pages are dropped too).
	 *
	 * @return array<array<string,mixed>>
	 */
	private function hydrateOrderTree(?string $collection, bool $publicOnly): array
	{
		$collectionId = $collection ?? $this->builderConfig->getPagesCollectionId();
		$pageById     = $this->fetchPageRecordsById($collectionId);

		if ($pageById === []) {
			return [];
		}

		$tree = $this->orderService->read($collectionId);

		return $this->attachRecords($tree, $pageById, $publicOnly);
	}

	/**
	 * @param  list<array{id:string,children:list<array<string,mixed>>}> $tree
	 * @param  array<string,array<string,mixed>>                          $pageById
	 * @return list<array<string,mixed>>
	 */
	private function attachRecords(array $tree, array $pageById, bool $publicOnly): array
	{
		$out = [];
		foreach ($tree as $node) {
			$id = $node['id'];
			if (!isset($pageById[$id])) {
				continue;
			}
			$record = $pageById[$id];

			if ($publicOnly && (!empty($record['draft']) || ($record['nav'] ?? true) !== true)) {
				continue;
			}

			$childrenRaw = $node['children'];
			/** @var list<array{id:string,children:list<array<string,mixed>>}> $childrenRaw */
			$record['children'] = $this->attachRecords($childrenRaw, $pageById, $publicOnly);
			$out[]              = $record;
		}

		return $out;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchPageRecordsById(string $collectionId): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Exception) {
			return [];
		}

		$by = [];
		foreach ($index->objects as $page) {
			$id = (string)($page['id'] ?? '');
			if ($id !== '') {
				$by[$id] = $page;
			}
		}

		return $by;
	}

	/**
	 * Find a node by id anywhere in a tree.
	 *
	 * @param  array<array<string,mixed>>  $tree
	 * @return array<string,mixed>|null
	 */
	private function findNode(array $tree, string $id): ?array
	{
		foreach ($tree as $node) {
			if ((string)($node['id'] ?? '') === $id) {
				return $node;
			}
			$children = $node['children'] ?? [];
			if (is_array($children)) {
				$found = $this->findNode($children, $id);
				if ($found !== null) {
					return $found;
				}
			}
		}

		return null;
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

	// -------------------------
	// Private — Assets
	// -------------------------

	/**
	 * Resolve an asset path to a full URL with cache busting.
	 */
	private function resolveAssetUrl(string $path): string
	{
		$basePath = $this->getAssetsBasePath();
		$manifest = $this->loadManifest();

		// Check manifest for hashed filename
		if ($manifest !== null && isset($manifest[$path])) {
			return $basePath . '/' . $manifest[$path]['file'];
		}

		// Shorthand key without directory prefix (e.g., 'style.css' vs 'css/style.css')
		if ($manifest !== null && !str_contains($path, '/')) {
			foreach ($manifest as $entry) {
				if (basename($entry['file']) === basename($path)) {
					return $basePath . '/' . $entry['file'];
				}
			}
		}

		// Fall back to mtime cache busting
		$diskPath = $this->config->docroot . '/' . ltrim($basePath, '/') . '/' . $path;
		if (file_exists($diskPath)) {
			$mtime = filemtime($diskPath);

			return $basePath . '/' . $path . '?v=' . ($mtime ?: '0');
		}

		// File not found — return raw path
		return $basePath . '/' . $path;
	}

	/**
	 * Get the public base path for assets.
	 */
	private function getAssetsBasePath(): string
	{
		$assetsPath = (string)($this->config->builder['assetsPath'] ?? 'assets');
		if ($assetsPath === '') {
			$assetsPath = 'assets';
		}

		return '/' . trim($assetsPath, '/');
	}

	/**
	 * Load and cache the asset manifest (Vite/esbuild format).
	 *
	 * @return array<string,array{file:string}>|null
	 */
	private function loadManifest(): ?array
	{
		if ($this->manifestCache !== false) {
			return $this->manifestCache;
		}

		$basePath     = $this->getAssetsBasePath();
		$manifestPath = $this->config->docroot . '/' . ltrim($basePath, '/') . '/manifest.json';

		if (!file_exists($manifestPath)) {
			$this->manifestCache = null;

			return null;
		}

		$contents = file_get_contents($manifestPath);
		if ($contents === false) {
			$this->manifestCache = null;

			return null;
		}

		$decoded = json_decode($contents, true);
		if (!is_array($decoded)) {
			$this->manifestCache = null;

			return null;
		}

		/** @var array<string,array{file:string}> $decoded */
		$this->manifestCache = $decoded;

		return $decoded;
	}
}
