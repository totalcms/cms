<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

readonly class WatermarkFactory
{
	public function __construct(
		private TextWatermarkFactory $textWatermarkFactory,
		private Config $config,
		private StorageAdapterInterface $filesystem,
		private EditionFeatureService $editionFeatures,
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

		// Silently skip image watermarks if edition doesn't support it
		if (!$this->editionFeatures->can(EditionFeature::IMAGE_WATERMARKS)) {
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

	/**
	 * @param array<string,mixed> $params watermark parameters
	 * @param int $baseImageWidth width of the base image for auto-scaling check
	 */
	public function createTextWatermark(array $params = [], int $baseImageWidth = 0): ?Watermark
	{
		if (!isset($params['marktext']) || empty($params['marktext'])) {
			return null;
		}

		// Silently skip text watermarks if edition doesn't support it
		if (!$this->editionFeatures->can(EditionFeature::TEXT_WATERMARKS)) {
			return null;
		}

		// Generate the text watermark image
		$textWatermarkPath = $this->textWatermarkFactory->generateTextWatermark($params);

		// Auto-scale if watermark is wider than base image (unless user explicitly set marktextw)
		$markw = $params['marktextw'] ?? null;
		if ($markw === null && $baseImageWidth > 0) {
			// Get watermark dimensions
			$watermarkPath = TextWatermarkFactory::WATERMARK_DIR . '/' . $textWatermarkPath;
			try {
				if ($this->filesystem->fileExists($watermarkPath)) {
					$watermarkContent = $this->filesystem->read($watermarkPath);
					$watermarkSize    = @getimagesizefromstring($watermarkContent);
					if ($watermarkSize !== false && $watermarkSize[0] > $baseImageWidth) {
						// Watermark is wider than base image, scale it down to 90% for safety margin
						// (allows room for x/y offsets without overflowing)
						$markw = '90w';
					}
				}
			} catch (\Exception) {
				// Silently fail - just don't auto-scale if we can't check dimensions
			}
		}

		return new Watermark(
			mark      : $textWatermarkPath,
			markpos   : $params['marktextpos'] ?? 'center',
			markw     : $markw, // Auto-scales only if watermark is too wide
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
