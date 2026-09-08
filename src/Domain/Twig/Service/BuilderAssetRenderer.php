<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Support\Config;

/**
 * Site Builder asset helpers behind `cms.builder.asset()`, `css()`, `js()`
 * and `preload()`: resolve a path against the Vite/esbuild manifest (hashed
 * filenames), else cache-bust by mtime, else return the raw path. The
 * manifest is read once per request.
 *
 * BuilderTwigAdapter is the Twig-facing entry point and delegates here.
 */
class BuilderAssetRenderer
{
	/** @var array<string,array{file:string}>|false|null false = not loaded yet */
	private array|false|null $manifestCache = false;

	public function __construct(
		private readonly Config $config,
	) {
	}


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

		$basePath = $this->getAssetsBasePath();
		$assetDir = $this->config->docroot . '/' . ltrim($basePath, '/');

		// Vite 4 and most other build tools write `manifest.json` at the root
		// of the output directory. Vite 5+ moved it under `.vite/` by default.
		// Check both so customers can plug in either layout without having to
		// override their build config. Our bundled scaffold pins the manifest
		// at the root via `manifest: 'manifest.json'`, so this fallback only
		// fires for BYO Vite-5 projects.
		$manifestPath = $assetDir . '/manifest.json';
		if (!file_exists($manifestPath)) {
			$manifestPath = $assetDir . '/.vite/manifest.json';
		}

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
