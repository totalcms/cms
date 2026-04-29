<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for builder navigation and asset helpers.
 *
 * Accessed in Twig as `cms.builder.*`.
 */
class BuilderTwigAdapter
{
	/** @var array<string,array{file:string}>|null|false false = not loaded yet */
	private array|null|false $manifestCache = false;

	public function __construct(
		private readonly BuilderConfigService $builderConfig,
		private readonly IndexReader $indexReader,
		private readonly Config $config,
	) {
	}

	// -------------------------
	// Navigation
	// -------------------------

	/**
	 * Get top-level navigation pages (where parent is empty).
	 *
	 * Returns published, nav-visible pages sorted by sort order.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function nav(?string $collection = null): array
	{
		$pages = $this->fetchNavPages($collection);

		return array_values(array_filter($pages, fn (array $page): bool => ($page['parent'] ?? '') === ''));
	}

	/**
	 * Get child navigation pages for a specific parent.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function subnav(string $parentId, ?string $collection = null): array
	{
		$pages = $this->fetchNavPages($collection);

		return array_values(array_filter($pages, fn (array $page): bool => ($page['parent'] ?? '') === $parentId));
	}

	/**
	 * Get the full navigation tree with nested children.
	 *
	 * Each page gets a `children` key containing its child pages, recursively.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function navTree(?string $collection = null): array
	{
		return $this->buildTree($this->fetchNavPages($collection));
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
		$type = !empty($options['module']) ? ' type="module"' : '';

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
	 * Fetch all navigation-ready pages: published, nav-visible, sorted.
	 *
	 * @return array<array<string,mixed>>
	 */
	private function fetchNavPages(?string $collection): array
	{
		$collectionId = $collection ?? $this->builderConfig->getPagesCollectionId();

		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Exception) {
			return [];
		}

		return $index->objects
			->filter(fn (array $page): bool => empty($page['draft']) && ($page['nav'] ?? true) === true)
			->sortBy('sort')
			->values()
			->toArray();
	}

	/**
	 * Build a nested tree from a flat list of pages using the parent field.
	 *
	 * @param array<array<string,mixed>> $pages
	 *
	 * @return array<array<string,mixed>>
	 */
	private function buildTree(array $pages): array
	{
		$byParent = [];
		foreach ($pages as $page) {
			$parent = (string)($page['parent'] ?? '');
			$byParent[$parent][] = $page;
		}

		$attach = function (array $page) use (&$attach, $byParent): array {
			$id = (string)($page['id'] ?? '');
			$children = $byParent[$id] ?? [];
			$page['children'] = array_map($attach, $children);

			return $page;
		};

		$roots = $byParent[''] ?? [];

		return array_map($attach, $roots);
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
