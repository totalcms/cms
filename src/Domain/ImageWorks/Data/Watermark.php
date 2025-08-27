<?php

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;

/**
 * Simple watermark data object with standard Glide watermark properties.
 */
readonly class Watermark
{
	public function __construct(
		public ?string $mark      = null,
		public ?string $markpos   = null,
		public ?string $markw     = null,
		public ?string $markh     = null,
		public ?string $markx     = null,
		public ?string $marky     = null,
		public ?string $markfit   = null,
		public ?string $markpad   = null,
		public ?string $markalpha = null,
		public string $path       = TextWatermarkFactory::WATERMARK_DIR,
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
