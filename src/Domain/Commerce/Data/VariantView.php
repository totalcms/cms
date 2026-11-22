<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

use TotalCMS\Domain\Property\Data\PriceData;

/**
 * One option of a product, as the store reads it.
 */
final readonly class VariantView
{
	/**
	 * @param float|null          $price   null when the variant has no price of its own (empty or 0) and the product's applies
	 * @param array<string,mixed> $options what the variant is, such as ['size' => 'L']
	 */
	public function __construct(
		public string $id,
		public string $title,
		public string $sku,
		public ?float $price,
		public int $stock,
		public bool $active,
		public array $options,
		public string $image,
	) {
	}

	/**
	 * @param array<mixed> $item one item of a product's `variants` deck
	 */
	public static function fromItem(string $id, array $item): self
	{
		$price   = $item['price'] ?? null;
		$options = $item['options'] ?? [];

		return new self(
			id: $id,
			title: is_scalar($item['title'] ?? null) ? (string)$item['title'] : '',
			sku: is_scalar($item['sku'] ?? null) ? (string)$item['sku'] : '',
			price: self::ownPrice($price),
			stock: is_numeric($item['stock'] ?? null) ? (int)$item['stock'] : 0,
			active: filter_var($item['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
			options: is_array($options) ? $options : [],
			image: '',
		);
	}

	/**
	 * The variant's own price, or null when it has none and the product's
	 * applies. The price field stores an empty price as 0, so 0 cannot mean
	 * "free" here: read as free, every variant left blank would be given away.
	 */
	private static function ownPrice(mixed $price): ?float
	{
		$own = is_int($price) || is_float($price) || is_string($price) ? (float)PriceData::normalize($price) : 0.0;

		return $own > 0 ? $own : null;
	}
}
