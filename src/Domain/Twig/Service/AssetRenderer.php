<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Data\FrontendAsset;

/**
 * Renders FrontendAsset records into HTML tag blocks for the document head
 * or body. Stateless — assumes each asset's `url` is already absolute (the
 * adapter prepends the API base when records are added to its lists).
 *
 * Tag construction goes through HTMLUtils so attribute escaping and shape
 * stay consistent with the rest of the rendering surface.
 */
final class AssetRenderer
{
	/**
	 * Render head: stylesheets first, then preload hints (regardless of the
	 * asset's own position), then any head-positioned scripts.
	 *
	 * @param list<FrontendAsset> $assets
	 */
	public static function head(array $assets): string
	{
		$html = '';

		foreach ($assets as $asset) {
			if ($asset->type === 'css' && $asset->position === 'head') {
				$html .= self::stylesheet($asset->url) . "\n";
			}
		}

		// Preload hints — always emitted in head regardless of the asset's own position.
		// ES modules use <link rel="modulepreload"> so the browser caches them with the
		// correct module key and avoids double-fetching when the <script type="module">
		// tag later requests the same URL.
		foreach ($assets as $asset) {
			if (!$asset->preload) {
				continue;
			}
			$html .= self::preload($asset->url, $asset->type, $asset->module) . "\n";
		}

		foreach ($assets as $asset) {
			if ($asset->type === 'js' && $asset->position === 'head') {
				$html .= self::script($asset->url, $asset->module) . "\n";
			}
		}

		return $html;
	}

	/**
	 * Render body: any body-positioned CSS first (rare), then scripts.
	 *
	 * @param list<FrontendAsset> $assets
	 */
	public static function body(array $assets): string
	{
		$html = '';

		foreach ($assets as $asset) {
			if ($asset->type === 'css' && $asset->position === 'body') {
				$html .= self::stylesheet($asset->url) . "\n";
			}
		}

		foreach ($assets as $asset) {
			if ($asset->type === 'js' && $asset->position === 'body') {
				$html .= self::script($asset->url, $asset->module) . "\n";
			}
		}

		return $html;
	}

	private static function stylesheet(string $href): string
	{
		return HTMLUtils::inlineElement('link', ['rel' => 'stylesheet', 'href' => $href]);
	}

	private static function script(string $src, bool $module): string
	{
		$attributes = ['src' => $src];
		if ($module) {
			$attributes = ['type' => 'module'] + $attributes;
		}

		return HTMLUtils::element('script', '', $attributes);
	}

	private static function preload(string $href, string $type, bool $module): string
	{
		if ($type === 'js' && $module) {
			return HTMLUtils::inlineElement('link', ['rel' => 'modulepreload', 'href' => $href]);
		}

		$as = $type === 'css' ? 'style' : 'script';

		return HTMLUtils::inlineElement('link', ['rel' => 'preload', 'as' => $as, 'href' => $href]);
	}
}
