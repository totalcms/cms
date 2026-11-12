<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * One card on the Share Cards utility: the page's path and share URL, the
 * image to show (`$imageSrc`, on this install), the domain a social site
 * prints, the record's badges, and the head the page rendered (`$head`:
 * og:title, og:description, and why the card is what it is).
 */
final readonly class ShareCard
{
	public function __construct(
		public string $path,
		public string $shareUrl,
		public string $imageSrc,
		public string $domain,
		public string $editUrl,
		public bool $draft,
		public bool $noindex,
		public RenderedShareHead $head,
	) {
	}

	/**
	 * Worth a look before launch: a template that fails, a card that fell
	 * back, or no description to share. A page that is not an HTML document
	 * has no card to get wrong.
	 */
	public function isProblem(): bool
	{
		if (!$this->head->document) {
			return false;
		}

		return $this->head->failed || ($this->head->reason?->isProblem() ?? false) || $this->head->ogDescription === '';
	}
}
