<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Media\Generator\BarcodeGenerator;

/**
 * Twig Adapter with Barcode Generation.
 */
readonly class BarcodeTwigAdapter
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
		return $this->safely(fn (): string => $this->generator->code128($data, $options));
	}

	/**
	 * Generate Code 39 barcode (alphanumeric with some symbols).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code39(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->code39($data, $options));
	}

	/**
	 * Generate Code 93 barcode (alphanumeric).
	 *
	 * @param array<string,mixed> $options
	 */
	public function code93(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->code93($data, $options));
	}

	/**
	 * Generate EAN-13 barcode (13-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function ean13(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->ean13($data, $options));
	}

	/**
	 * Generate EAN-8 barcode (8-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function ean8(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->ean8($data, $options));
	}

	/**
	 * Generate UPC-A barcode (12-digit product codes).
	 *
	 * @param array<string,mixed> $options
	 */
	public function upca(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->upca($data, $options));
	}

	/**
	 * Generate UPC-E barcode (8-digit compressed UPC).
	 *
	 * @param array<string,mixed> $options
	 */
	public function upce(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->upce($data, $options));
	}

	/**
	 * Generate Interleaved 2 of 5 barcode (numeric only).
	 *
	 * @param array<string,mixed> $options
	 */
	public function i25(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->i25($data, $options));
	}

	/**
	 * Generate Codabar barcode (numeric with start/stop characters).
	 *
	 * @param array<string,mixed> $options
	 */
	public function codabar(string $data, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->codabar($data, $options));
	}

	/**
	 * Generate custom barcode with specific type.
	 *
	 * @param array<string,mixed> $options
	 */
	public function custom(string $data, string $type, array $options = []): string
	{
		return $this->safely(fn (): string => $this->generator->custom($data, $type, $options));
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

		return $this->safely(fn (): string => match ($length) {
			7, 8    => $this->generator->ean8($data, $options),
			11, 12  => $this->generator->upca($data, $options),
			13      => $this->generator->ean13($data, $options),
			default => throw new \InvalidArgumentException("Invalid product code length: {$length}. Expected 7-8, 11-13 digits."),
		});
	}

	/**
	 * Generate text/alphanumeric barcode (auto-selects best type).
	 *
	 * @param array<string,mixed> $options
	 */
	public function text(string $data, array $options = []): string
	{
		// Use Code 128 as default for text/alphanumeric data
		return $this->safely(fn (): string => $this->generator->code128($data, $options));
	}

	/**
	 * Generate numeric-only barcode (auto-selects best type).
	 *
	 * @param array<string,mixed> $options
	 */
	public function numeric(string $data, array $options = []): string
	{
		return $this->safely(function () use ($data, $options): string {
			if (!preg_match('/^\d+$/', $data)) {
				throw new \InvalidArgumentException('Numeric barcode requires digits only');
			}

			// Use Interleaved 2 of 5 for numeric data
			return $this->generator->i25($data, $options);
		});
	}

	/**
	 * Render a barcode, or an HTML comment saying why it could not be.
	 *
	 * A template must not be able to take a page down over one bad barcode
	 * value. Every other Twig adapter here degrades the same way — returning
	 * '' or [] rather than throwing — and a barcode is decoration on someone
	 * else's page, not the page itself.
	 *
	 * The reason is not swallowed: it goes into the markup as a comment, so
	 * whoever is building the template sees it in View Source without having
	 * to enable anything, while a visitor sees nothing. The generator still
	 * throws for callers who can handle it — this softening is only for the
	 * Twig surface.
	 *
	 * @param \Closure(): string $render
	 */
	private function safely(\Closure $render): string
	{
		try {
			return $render();
		} catch (\Throwable $e) {
			return '<!-- barcode: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE) . ' -->';
		}
	}
}
