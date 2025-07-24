<?php

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Domain\ImageWorks\Service\TextWatermark;

/**
 * Processes watermark parameters and creates Watermark data objects.
 * 
 * Handles the logic for determining watermark types, generating text watermarks,
 * and preparing watermark configurations for sequential processing.
 */
final class WatermarkProcessor
{
	public function __construct(
		private TextWatermark $textWatermark,
	) {
	}

	/**
	 * Process parameters and create watermark objects.
	 *
	 * @param array<string,mixed> $params Input parameters
	 * @return WatermarkResult
	 */
	public function processWatermarks(array $params): WatermarkResult
	{
		$imageWatermark = Watermark::createImageWatermark($params);
		$textWatermark = null;

		// Generate text watermark if requested
		if (isset($params['marktext']) && !empty($params['marktext'])) {
			try {
				$textWatermarkPath = $this->textWatermark->generateTextWatermark($params);
				$textWatermark = Watermark::createTextWatermark($params, $textWatermarkPath);
			} catch (\Exception $e) {
				error_log('Text watermark generation failed: ' . $e->getMessage());
			}
		}

		// Clean parameters by removing watermark-specific parameters
		$cleanedParams = $this->removeWatermarkParameters($params);

		return new WatermarkResult(
			imageWatermark: $imageWatermark,
			textWatermark: $textWatermark,
			cleanedParams: $cleanedParams
		);
	}

	/**
	 * Remove watermark parameters from the params array.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function removeWatermarkParameters(array $params): array
	{
		$cleanedParams = $params;
		$parametersToRemove = Watermark::getParametersToRemove();

		foreach ($parametersToRemove as $param) {
			unset($cleanedParams[$param]);
		}

		return $cleanedParams;
	}
}