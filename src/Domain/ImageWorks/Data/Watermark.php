<?php

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;

/**
 * Simple watermark data object with standard Glide watermark properties.
 */
final class Watermark
{
	public function __construct(
		public readonly ?string $mark      = null,
		public readonly ?string $markpos   = null,
		public readonly ?string $markw     = null,
		public readonly ?string $markh     = null,
		public readonly ?string $markx     = null,
		public readonly ?string $marky     = null,
		public readonly ?string $markfit   = null,
		public readonly ?string $markpad   = null,
		public readonly ?string $markalpha = null,
		public readonly string $path       = TextWatermarkFactory::WATERMARK_DIR,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$params = [
			'mark'      => $this->mark,
			'markpos'   => $this->markpos,
			'markw'     => $this->markw,
			'markh'     => $this->markh,
			'markx'     => $this->markx,
			'marky'     => $this->marky,
			'markfit'   => $this->markfit,
			'markpad'   => $this->markpad,
			'markalpha' => $this->markalpha,
		];

		return array_filter($params);
	}

	/**
	 * Check if this watermark has any data.
	 */
	public function isEmpty(): bool
	{
		return $this->mark === null;
	}

}
