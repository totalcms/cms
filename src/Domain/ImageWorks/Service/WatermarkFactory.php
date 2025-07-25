<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Support\Config;

final class WatermarkFactory
{
	public function __construct(
		private TextWatermarkFactory $textWatermarkFactory,
		private Config $config,
	) {
	}

	public function watermarkPath(?string $watermarkGalleryId = null): string
	{
		$objectID = $watermarkGalleryId ?? $this->config->imageworks['watermarksGallery'];

		return sprintf('gallery/%s/gallery', $objectID);
	}

	/** @param array<string,mixed> $params watermark parameters */
	public function createImageWatermark(array $params = []): ?Watermark
	{
		if (!isset($params['mark']) || empty($params['mark'])) {
			return null;
		}

		return new Watermark(
			mark      : $params['mark'],
			markpos   : $params['markpos'] ?? 'bottom-right',
			markw     : $params['markw'] ?? '100w',
			markh     : $params['markh'] ?? null,
			markx     : $params['markx'] ?? null,
			marky     : $params['marky'] ?? null,
			markfit   : $params['markfit'] ?? null,
			markpad   : $params['markpad'] ?? null,
			markalpha : $params['markalpha'] ?? null,
			path      : $this->watermarkPath(),
		);
	}

	/** @param array<string,mixed> $params watermark parameters */
	public function createTextWatermark(array $params = []): ?Watermark
	{
		if (!isset($params['marktext']) || empty($params['marktext'])) {
			return null;
		}

		// Generate the text watermark image
		$textWatermarkPath = $this->textWatermarkFactory->generateTextWatermark($params);

		return new Watermark(
			mark      : $textWatermarkPath,
			markpos   : $params['marktextpos'] ?? 'center',
			markw     : $params['marktextw'] ?? '100w',
			markh     : $params['marktexth'] ?? null,
			markx     : $params['marktextx'] ?? null,
			marky     : $params['marktexty'] ?? null,
			markfit   : $params['marktextfit'] ?? null,
			markpad   : $params['marktextpad'] ?? null,
			markalpha : $params['marktextalpha'] ?? null,
			path      : TextWatermarkFactory::WATERMARK_DIR,
		);
	}
}
