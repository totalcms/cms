<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Data;

/**
 * Value object describing a single rendered asset (CSS or JS) with the
 * information AssetRenderer needs to emit a tag.
 *
 * Instances are produced by the registrars (Core* and ExtensionManager)
 * after they've resolved cache-busting URLs, and consumed by AssetRenderer
 * when it builds head/body markup.
 */
final readonly class FrontendAsset
{
	/**
	 * @param 'css'|'js'    $type
	 * @param 'head'|'body' $position
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function __construct(
		public string $type,
		public string $url,
		public string $position,
		public bool $module = false,
		public bool $preload = false,
	) {
	}
}
