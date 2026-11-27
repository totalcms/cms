<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

use TotalCMS\Domain\Property\Data\PriceData;

/**
 * One option of a product, as the store reads it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every field of a variant is one promoted property
 */
final readonly class VariantView
{
	/**
	 * @param float|null          $price   null when the variant has no price of its own (empty or 0) and the product's applies
	 * @param array<string,mixed> $options what the variant is, such as ['size' => 'L']
	 * @param string|null         $interval      for a plan: how often this variant is billed, when not as the product is
	 * @param int|null            $intervalCount for a plan: every how many of them, when not as the product is
	 * @param string|null         $billing       ProductView::BILLING_ONCE or BILLING_RECURRING when this variant is billed differently from its product, null when it follows the product
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
		public ?string $interval = null,
		public ?int $intervalCount = null,
		public ?string $billing = null,
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
			interval: is_string($item['interval'] ?? null) && trim($item['interval']) !== '' ? trim($item['interval']) : null,
			intervalCount: is_numeric($item['intervalCount'] ?? null) && (int)$item['intervalCount'] > 0 ? (int)$item['intervalCount'] : null,
			billing: in_array($item['billing'] ?? null, [ProductView::BILLING_ONCE, ProductView::BILLING_RECURRING], true) ? $item['billing'] : null,
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
