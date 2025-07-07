<?php

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Media\Generator\BarcodeGenerator;

/**
 * Twig Adapter with Barcode Generation.
 */
final class BarcodeTwigAdapter
{
	public function __construct(private BarcodeGenerator $generator)
	{
	}

	/**
	 * Generate Code 128 barcode (most versatile, alphanumeric).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code128(string $data, array $options = []): string
	{
		return $this->generator->code128($data, $options);
	}

	/**
	 * Generate Code 39 barcode (alphanumeric with some symbols).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code39(string $data, array $options = []): string
	{
		return $this->generator->code39($data, $options);
	}

	/**
	 * Generate Code 93 barcode (alphanumeric).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code93(string $data, array $options = []): string
	{
		return $this->generator->code93($data, $options);
	}

	/**
	 * Generate EAN-13 barcode (13-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function ean13(string $data, array $options = []): string
	{
		return $this->generator->ean13($data, $options);
	}

	/**
	 * Generate EAN-8 barcode (8-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function ean8(string $data, array $options = []): string
	{
		return $this->generator->ean8($data, $options);
	}

	/**
	 * Generate UPC-A barcode (12-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function upca(string $data, array $options = []): string
	{
		return $this->generator->upca($data, $options);
	}

	/**
	 * Generate UPC-E barcode (8-digit compressed UPC).
	 *
	 * @param array<string,mixed> $options
	 */
	public function upce(string $data, array $options = []): string
	{
		return $this->generator->upce($data, $options);
	}

	/**
	 * Generate Interleaved 2 of 5 barcode (numeric only).
	 *
	 * @param array<string,mixed> $options
	 */
	public function i25(string $data, array $options = []): string
	{
		return $this->generator->i25($data, $options);
	}

	/**
	 * Generate Codabar barcode (numeric with start/stop characters).
	 *
	 * @param array<string,mixed> $options
	 */
	public function codabar(string $data, array $options = []): string
	{
		return $this->generator->codabar($data, $options);
	}

	/**
	 * Generate custom barcode with specific type.
	 *
	 * @param array<string,mixed> $options
	 */
	public function custom(string $data, string $type, array $options = []): string
	{
		return $this->generator->custom($data, $type, $options);
	}

	/**
	 * Get list of supported barcode types.
	 *
	 * @return array<string>
	 */
	public function supportedTypes(): array
	{
		return $this->generator->getSupportedTypes();
	}

	/**
	 * Generate product barcode (auto-detects EAN-13/EAN-8/UPC based on length).
	 *
	 * @param array<string,mixed> $options
	 */
	public function product(string $data, array $options = []): string
	{
		$length = strlen($data);

		return match ($length) {
			7, 8 => $this->generator->ean8($data, $options),
			11      => $this->generator->upca($data, $options),
			12      => $this->generator->upca($data, $options),
			13      => $this->generator->ean13($data, $options),
			default => throw new \InvalidArgumentException("Invalid product code length: {$length}. Expected 7-8, 11-13 digits."),
		};
	}

	/**
	 * Generate text/alphanumeric barcode (auto-selects best type).
	 *
	 * @param array<string,mixed> $options
	 */
	public function text(string $data, array $options = []): string
	{
		// Use Code 128 as default for text/alphanumeric data
		return $this->generator->code128($data, $options);
	}

	/**
	 * Generate numeric-only barcode (auto-selects best type).
	 *
	 * @param array<string,mixed> $options
	 */
	public function numeric(string $data, array $options = []): string
	{
		// Validate numeric data
		if (!preg_match('/^\d+$/', $data)) {
			throw new \InvalidArgumentException('Numeric barcode requires digits only');
		}

		// Use Interleaved 2 of 5 for numeric data
		return $this->generator->i25($data, $options);
	}
}
