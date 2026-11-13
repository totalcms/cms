<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * A draft overlay drawn on one record, for the overlay designer: why the
 * card came out as it did (the Share Cards reasons) and the image, as a
 * data URI. `$asIs` says the record has "Use the Image as Is" on, which the
 * preview passes over but real shares do not.
 */
final readonly class OverlayPreview
{
	public function __construct(
		public SocialCardReason $reason,
		public string $detail = '',
		public ?int $layer = null,
		public bool $asIs = false,
		public ?string $image = null,
	) {
	}

	/**
	 * The Share Cards message key: a refusal that names no layer is about the
	 * list as a whole.
	 */
	public function messageKey(): string
	{
		return $this->reason === SocialCardReason::LayerRefused && $this->layer === null ? 'layers-refused' : $this->reason->value;
	}
}
