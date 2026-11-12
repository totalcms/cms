<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * One record's share image: the image that won the SEO chain, and how to
 * draw it. Built the same way when the page head prints its URL and when the
 * share-image route renders it, so the two always agree.
 */
final readonly class SocialCard
{
	/**
	 * @param string $collection The record the card is for: the page head's subject
	 * @param string $imageCollection Where the image lives: the record itself, or the Site SEO record for the site's default image
	 * @param bool $gallery Whether the image property is a gallery, drawn from its first image
	 * @param array<string,mixed> $params The 1200×630 share size, the overlay's fit and its filled `marks`; no `marks` for a plain image
	 * @param string $hash Keyed hash of all of the above, carried in the URL as `v`
	 * @param string $firstText The first text layer's text, the alt for an image that has none
	 */
	public function __construct(
		public string $collection,
		public string $id,
		public string $imageCollection,
		public string $imageId,
		public string $imageProperty,
		public bool $gallery,
		public array $params,
		public string $hash,
		public string $firstText,
	) {
	}

	/** Whether there is an overlay to draw, rather than the plain image. */
	public function hasOverlay(): bool
	{
		return ($this->params['marks'] ?? []) !== [];
	}
}
