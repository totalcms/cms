<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/** One object's `seo` card, typed. Empty strings and false mean "derive". */
final readonly class SeoFields
{
	/** @param array<string,mixed> $image */
	public function __construct(
		public string $title,
		public string $socialTitle,
		public string $description,
		public array $image,
		public string $canonical,
		public bool $noindex,
		public bool $nofollow,
		public string $jsonldType,
	) {
	}

	/** @param array<string,mixed> $seo */
	public static function fromArray(array $seo): self
	{
		$str  = static fn (string $k): string => trim((string)($seo[$k] ?? ''));
		$bool = static fn (string $k): bool => filter_var($seo[$k] ?? false, FILTER_VALIDATE_BOOL);

		return new self(
			title: $str('title'),
			socialTitle: $str('socialTitle'),
			description: $str('description'),
			image: is_array($seo['image'] ?? null) ? $seo['image'] : [],
			canonical: $str('canonical'),
			noindex: $bool('noindex'),
			nofollow: $bool('nofollow'),
			jsonldType: $str('jsonldType'),
		);
	}

	public function hasImage(): bool
	{
		return ($this->image['name'] ?? '') !== '' && (int)($this->image['size'] ?? 0) > 0;
	}
}
