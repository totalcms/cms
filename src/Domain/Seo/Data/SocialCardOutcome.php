<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * A share card and why it came out that way. `$ogImage` is the og:image the
 * head printed; `$layer` and `$detail` name the layer and the parameter
 * ImageWorks refused, for SocialCardReason::LayerRefused.
 */
final readonly class SocialCardOutcome
{
	public function __construct(
		public SocialCardReason $reason,
		public ?SocialCard $card,
		public string $ogImage,
		public string $detail = '',
		public ?int $layer = null,
	) {
	}

	public function withOgImage(string $ogImage): self
	{
		return new self($this->reason, $this->card, $ogImage, $this->detail, $this->layer);
	}
}
