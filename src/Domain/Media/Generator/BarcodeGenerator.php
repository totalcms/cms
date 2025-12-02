<?php

namespace TotalCMS\Domain\Media\Generator;

use Com\Tecnick\Barcode\Barcode;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

/**
 * Barcode Generator utility using tecnickcom/tc-lib-barcode.
 */
class BarcodeGenerator
{
	private readonly Barcode $barcode;

	public function __construct(
		private readonly ?EditionFeatureService $editionFeatures = null,
	) {
		$this->barcode = new Barcode();
	}

	/**
	 * Generate SVG barcode output.
	 */
	private function generateSVG(string $data, string $type, int $width = -1, int $height = -1, string $color = 'black'): string
	{
		// Barcodes require Pro edition
		if ($this->editionFeatures !== null) {
			$this->editionFeatures->canOrFail(EditionFeature::BARCODES);
		}

		try {
			$barcodeObj = $this->barcode->getBarcodeObj($type, $data, $width, $height, $color);

			// Use getInlineSvgCode() for HTML embedding (no XML declaration)
			$svg = $barcodeObj->getInlineSvgCode();

			// Add cms-barcode class to the SVG element
			return (string)preg_replace('/<svg/', '<svg class="cms-barcode"', $svg, 1);
		} catch (\Exception $e) {
			throw new \InvalidArgumentException("Unable to generate barcode with type: {$type}. Error: " . $e->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * Generate HTML output with embedded SVG.
	 */
	private function generateHTML(string $data, string $type, int $width = -1, int $height = -1, string $color = 'black'): string
	{
		$svg = $this->generateSVG($data, $type, $width, $height, $color);

		return sprintf(
			'<div class="barcode-container" data-type="%s" data-value="%s">%s</div>',
			htmlspecialchars($type),
			htmlspecialchars($data),
			$svg
		);
	}

	/**
	 * Generate Code 128 barcode (most common, supports alphanumeric).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code128(string $data, array $options = []): string
	{
		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'C128', $width, $height, $color);
		}

		return $this->generateHTML($data, 'C128', $width, $height, $color);
	}

	/**
	 * Generate Code 39 barcode (alphanumeric with some symbols).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code39(string $data, array $options = []): string
	{
		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'C39', $width, $height, $color);
		}

		return $this->generateHTML($data, 'C39', $width, $height, $color);
	}

	/**
	 * Generate EAN-13 barcode (13-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function ean13(string $data, array $options = []): string
	{
		// Validate EAN-13 format (12 or 13 digits)
		if (!preg_match('/^\d{12,13}$/', $data)) {
			throw new \InvalidArgumentException('EAN-13 requires 12 or 13 digits');
		}

		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'EAN13', $width, $height, $color);
		}

		return $this->generateHTML($data, 'EAN13', $width, $height, $color);
	}

	/**
	 * Generate EAN-8 barcode (8-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function ean8(string $data, array $options = []): string
	{
		// Validate EAN-8 format (7 or 8 digits)
		if (!preg_match('/^\d{7,8}$/', $data)) {
			throw new \InvalidArgumentException('EAN-8 requires 7 or 8 digits');
		}

		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'EAN8', $width, $height, $color);
		}

		return $this->generateHTML($data, 'EAN8', $width, $height, $color);
	}

	/**
	 * Generate UPC-A barcode (12-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function upca(string $data, array $options = []): string
	{
		// Validate UPC-A format (11 or 12 digits)
		if (!preg_match('/^\d{11,12}$/', $data)) {
			throw new \InvalidArgumentException('UPC-A requires 11 or 12 digits');
		}

		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'UPCA', $width, $height, $color);
		}

		return $this->generateHTML($data, 'UPCA', $width, $height, $color);
	}

	/**
	 * Generate UPC-E barcode (8-digit compressed UPC).
	 *
	 * @param array<string,mixed> $options
	 */
	public function upce(string $data, array $options = []): string
	{
		// Validate UPC-E format (7 or 8 digits)
		if (!preg_match('/^\d{7,8}$/', $data)) {
			throw new \InvalidArgumentException('UPC-E requires 7 or 8 digits');
		}

		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'UPCE', $width, $height, $color);
		}

		return $this->generateHTML($data, 'UPCE', $width, $height, $color);
	}

	/**
	 * Generate Code 93 barcode (alphanumeric).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code93(string $data, array $options = []): string
	{
		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'C93', $width, $height, $color);
		}

		return $this->generateHTML($data, 'C93', $width, $height, $color);
	}

	/**
	 * Generate Interleaved 2 of 5 barcode (numeric only).
	 *
	 * @param array<string,mixed> $options
	 */
	public function i25(string $data, array $options = []): string
	{
		// Validate numeric format
		if (!preg_match('/^\d+$/', $data)) {
			throw new \InvalidArgumentException('Interleaved 2 of 5 requires numeric data only');
		}

		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'I25', $width, $height, $color);
		}

		return $this->generateHTML($data, 'I25', $width, $height, $color);
	}

	/**
	 * Generate Codabar barcode (numeric with start/stop characters).
	 *
	 * @param array<string,mixed> $options
	 */
	public function codabar(string $data, array $options = []): string
	{
		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, 'CODABAR', $width, $height, $color);
		}

		return $this->generateHTML($data, 'CODABAR', $width, $height, $color);
	}

	/**
	 * Generate custom barcode with specific type.
	 *
	 * @param array<string,mixed> $options
	 */
	public function custom(string $data, string $type, array $options = []): string
	{
		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';
		$format = $options['format'] ?? 'html';

		if ($format === 'svg') {
			return $this->generateSVG($data, $type, $width, $height, $color);
		}

		return $this->generateHTML($data, $type, $width, $height, $color);
	}

	/**
	 * Get list of supported barcode types.
	 *
	 * @return array<string>
	 */
	public function getSupportedTypes(): array
	{
		return [
			'C128',      // Code 128
			'C39',       // Code 39
			'C93',       // Code 93
			'EAN13',     // European Article Number 13
			'EAN8',      // European Article Number 8
			'UPCA',      // Universal Product Code A
			'UPCE',      // Universal Product Code E
			'I25',       // Interleaved 2 of 5
			'CODABAR',   // Codabar
			'CODE11',    // Code 11
			'S25',       // Standard 2 of 5
			'POSTNET',   // POSTNET
			'PLANET',    // PLANET
			'RMS4CC',    // Royal Mail 4-state Customer Code
			'KIX',       // KIX (Klant index - Customer index)
			'IMB',       // Intelligent Mail Barcode
		];
	}
}
