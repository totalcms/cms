<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Support\Config;

/**
 * Scans the configured assets directory under the docroot and returns
 * files grouped by type (css, js, fonts, images, other) for the Site
 * Builder asset browser.
 *
 * Manifest detection (`manifest.json`) is exposed separately so the UI can
 * surface a "build is wired up" cue without listing the manifest itself.
 */
readonly class BuilderAssetScanner
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * @return array{css: list<string>, js: list<string>, fonts: list<string>, images: list<string>, other: list<string>, hasManifest: bool}
	 */
	public function scan(string $assetsPath): array
	{
		$result = ['css' => [], 'js' => [], 'fonts' => [], 'images' => [], 'other' => [], 'hasManifest' => false];
		$dir    = $this->config->docroot . '/' . trim($assetsPath, '/');

		if (!is_dir($dir)) {
			return $result;
		}

		$result['hasManifest'] = file_exists($dir . '/manifest.json');

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		);

		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || !$file->isFile()) {
				continue;
			}

			$ext      = strtolower($file->getExtension());
			$relative = str_replace($dir . '/', '', $file->getPathname());

			// Skip manifest.json and hidden files
			if ($relative === 'manifest.json' || str_starts_with(basename($relative), '.')) {
				continue;
			}

			match (true) {
				in_array($ext, ['css', 'scss', 'less'], true)                                     => $result['css'][]    = $relative,
				in_array($ext, ['js', 'mjs', 'ts'], true)                                         => $result['js'][]     = $relative,
				in_array($ext, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true)                      => $result['fonts'][]  = $relative,
				in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'avif'], true) => $result['images'][] = $relative,
				default                                                                           => $result['other'][]                                                                           = $relative,
			};
		}

		sort($result['css']);
		sort($result['js']);
		sort($result['fonts']);
		sort($result['images']);
		sort($result['other']);

		return $result;
	}
}
