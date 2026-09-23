<?php

namespace TotalCMS\Domain\Media\Generator;

use Com\Tecnick\Barcode\Barcode;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

/**
 * Renders barcodes as inline SVG (or an HTML wrapper around one). The named
 * symbologies are `custom()` plus the format check that symbology imposes;
 * the option handling lives once in {@see render()}.
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
	 * @param array<string,mixed> $options `width`, `height`, `color`, `format` (`html` | `svg`)
	 */
	private function render(string $data, string $type, array $options): string
	{
		$width  = $options['width'] ?? -1;
		$height = $options['height'] ?? -1;
		$color  = $options['color'] ?? 'black';

		if (($options['format'] ?? 'html') === 'svg') {
			return $this->generateSVG($data, $type, $width, $height, $color);
		}

		return $this->generateHTML($data, $type, $width, $height, $color);
	}

	private function assertMatches(string $data, string $pattern, string $message): void
	{
		if (!preg_match($pattern, $data)) {
			throw new \InvalidArgumentException($message);
		}
	}

	private function generateSVG(string $data, string $type, int $width = -1, int $height = -1, string $color = 'black'): string
	{
		// Barcodes require Pro edition
		if ($this->editionFeatures instanceof EditionFeatureService) {
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

	/** @param array<string,mixed> $options */
	public function code128(string $data, array $options = []): string
	{
		return $this->render($data, 'C128', $options);
	}

	/** @param array<string,mixed> $options */
	public function code39(string $data, array $options = []): string
	{
		return $this->render($data, 'C39', $options);
	}

	/** @param array<string,mixed> $options */
	public function code93(string $data, array $options = []): string
	{
		return $this->render($data, 'C93', $options);
	}

	/** @param array<string,mixed> $options */
	public function ean13(string $data, array $options = []): string
	{
		$this->assertMatches($data, '/^\d{12,13}$/', 'EAN-13 requires 12 or 13 digits');

		return $this->render($data, 'EAN13', $options);
	}

	/** @param array<string,mixed> $options */
	public function ean8(string $data, array $options = []): string
	{
		$this->assertMatches($data, '/^\d{7,8}$/', 'EAN-8 requires 7 or 8 digits');

		return $this->render($data, 'EAN8', $options);
	}

	/** @param array<string,mixed> $options */
	public function upca(string $data, array $options = []): string
	{
		$this->assertMatches($data, '/^\d{11,12}$/', 'UPC-A requires 11 or 12 digits');

		return $this->render($data, 'UPCA', $options);
	}

	/** @param array<string,mixed> $options */
	public function upce(string $data, array $options = []): string
	{
		$this->assertMatches($data, '/^\d{6,8}$/', 'UPC-E requires 6, 7 or 8 digits');

		return $this->render($this->upcePayload($data), 'UPCE', $options);
	}

	/** @param array<string,mixed> $options */
	public function i25(string $data, array $options = []): string
	{
		$this->assertMatches($data, '/^\d+$/', 'Interleaved 2 of 5 requires numeric data only');

		return $this->render($data, 'I25', $options);
	}

	/**
	 * The six data digits the encoder wants. An 8-digit code carries the
	 * number system in front and the check digit behind; a 7-digit one has
	 * only the number system (when it starts with 0 or 1) or only the check
	 * digit; six digits are already the payload. The encoder recomputes the
	 * check digit itself.
	 */
	private function upcePayload(string $data): string
	{
		return match (strlen($data)) {
			8       => substr($data, 1, 6),
			7       => in_array($data[0], ['0', '1'], true) ? substr($data, 1, 6) : substr($data, 0, 6),
			default => $data,
		};
	}

	/** @param array<string,mixed> $options */
	public function codabar(string $data, array $options = []): string
	{
		return $this->render($data, 'CODABAR', $options);
	}

	/**
	 * Any symbology tc-lib-barcode supports — see {@see getSupportedTypes()}.
	 *
	 * @param array<string,mixed> $options
	 */
	public function custom(string $data, string $type, array $options = []): string
	{
		return $this->render($data, $type, $options);
	}

	/** @return array<string> */
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
