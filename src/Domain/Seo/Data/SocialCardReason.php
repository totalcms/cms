<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * Why a share card is what it is: the overlay drawn, a deliberate plain
 * image, or the reason the overlay was dropped (SocialImageBuilder::explain).
 * The Share Cards utility shows one message per case.
 */
enum SocialCardReason: string
{
	case Drawn        = 'drawn';
	case AsIs         = 'as-is';
	case NoOverlay    = 'no-overlay';
	case NoRecord     = 'no-record';
	case Untraced     = 'untraced';
	case NoImage      = 'no-image';
	case LayerRefused = 'layer-refused';
	case LayersEmpty  = 'layers-empty';
	case NoSiteKey    = 'no-site-key';
	case HashMismatch = 'hash-mismatch';

	/**
	 * A card someone should look at before launch. Drawn, as-is and an unset
	 * overlay are choices; a page that builds its own values is deliberate.
	 */
	public function isProblem(): bool
	{
		return match ($this) {
			self::Drawn, self::AsIs, self::NoOverlay, self::NoRecord => false,
			default => true,
		};
	}
}
