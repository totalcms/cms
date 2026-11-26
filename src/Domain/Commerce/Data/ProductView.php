<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Property\Data\PriceData;

/**
 * A product, as the store reads it: typed, with the defaults filled in, so
 * the cart, pricing and checkout never pick through a raw object.
 *
 * A view says what the product is. Whether it may be bought is the
 * catalog's question, and what it costs is the pricing service's.
 */
final readonly class ProductView
{
	public const STATUS_ACTIVE = 'active';
	public const TYPE_DIGITAL  = 'digital';
	public const TYPE_PHYSICAL = 'physical';

	public const BILLING_ONCE      = 'once';
	public const BILLING_RECURRING = 'recurring';

	/**
	 * @param float                     $price            as the price field holds it; the pricing service turns it into minor units
	 * @param bool                      $requiresShipping always false for a digital product
	 * @param array<string,mixed>       $meta             fulfillment data, copied onto each order line
	 * @param array<string,VariantView> $variants         keyed by variant id
	 * @param string                    $image            the main image's URL, or ''
	 * @param string                    $billing          `once`, or `recurring` for a plan: billed again every interval until it is cancelled
	 * @param string                    $interval         for a plan: day, week, month or year; '' otherwise
	 * @param int                       $intervalCount    for a plan: billed every this many intervals; 0 otherwise
	 * @param int                       $trialDays        for a plan: free days before the first payment
	 * @param string                    $accessGroup      the access group its buyer is given, or ''
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList") One parameter for each thing a product is; built by fromObject().
	 */
	public function __construct(
		public string $id,
		public string $title,
		public string $sku,
		public string $status,
		public string $type,
		public float $price,
		public bool $taxable,
		public bool $requiresShipping,
		public bool $trackInventory,
		public int $stock,
		public bool $allowBackorder,
		public string $category,
		public array $meta,
		public bool $hasVariants,
		public array $variants,
		public string $image,
		public string $billing = self::BILLING_ONCE,
		public string $interval = '',
		public int $intervalCount = 0,
		public int $trialDays = 0,
		public string $accessGroup = '',
	) {
	}

	/**
	 * @param array<mixed> $object a product object, as stored
	 * @param string       $image  the main image's URL, when the caller has worked it out
	 */
	public static function fromObject(array $object, string $image = ''): self
	{
		$text = static fn (string $key, string $default = ''): string => is_scalar($object[$key] ?? null) && trim((string)$object[$key]) !== '' ? trim((string)$object[$key]) : $default;
		$flag = static fn (string $key, bool $default): bool => filter_var($object[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);

		$type     = $text('type', self::TYPE_PHYSICAL) === self::TYPE_DIGITAL ? self::TYPE_DIGITAL : self::TYPE_PHYSICAL;
		$price    = $object['price'] ?? 0;
		$meta     = $object['meta'] ?? [];
		$variants  = self::variants($object['variants'] ?? []);
		$recurring = $text('billing', self::BILLING_ONCE) === self::BILLING_RECURRING;

		return new self(
			id: $text('id'),
			title: $text('title'),
			sku: $text('sku'),
			status: $text('status', 'draft'),
			type: $type,
			price: is_int($price) || is_float($price) || is_string($price) ? (float)PriceData::normalize($price) : 0.0,
			taxable: $flag('taxable', true),
			requiresShipping: $type === self::TYPE_PHYSICAL && $flag('requiresShipping', true),
			trackInventory: $flag('trackInventory', false),
			stock: is_numeric($object['stock'] ?? null) ? (int)$object['stock'] : 0,
			allowBackorder: $flag('allowBackorder', false),
			category: $text('category'),
			meta: is_array($meta) ? $meta : [],
			hasVariants: $variants !== [],
			variants: $variants,
			image: $image,
			billing: $recurring ? self::BILLING_RECURRING : self::BILLING_ONCE,
			interval: $recurring ? $text('interval', 'month') : '',
			intervalCount: $recurring ? (is_numeric($object['intervalCount'] ?? null) ? (int)$object['intervalCount'] : 1) : 0,
			accessGroup: $text('accessGroup'),
		);
	}

	/** A plan: billed again every interval until it is cancelled. */
	public function isRecurring(): bool
	{
		return $this->billing === self::BILLING_RECURRING;
	}

	/**
	 * How often the product, or this variant of it, is billed: the
	 * variant's own interval and count where it has them, the product's
	 * otherwise. Null for a product paid for once.
	 *
	 * @throws CommerceException `product_unavailable` for a period no provider can bill
	 */
	public function recurrence(?VariantView $variant = null): ?Recurrence
	{
		if (!$this->isRecurring()) {
			return null;
		}

		try {
			return new Recurrence($variant->interval ?? $this->interval, $variant->intervalCount ?? $this->intervalCount, $this->trialDays);
		} catch (\InvalidArgumentException) {
			throw CommerceException::productUnavailable();
		}
	}

	public function isActive(): bool
	{
		return $this->status === self::STATUS_ACTIVE;
	}

	/**
	 * @return array<string,VariantView>
	 */
	private static function variants(mixed $deck): array
	{
		$variants = [];
		foreach (is_array($deck) ? $deck : [] as $id => $item) {
			if (is_array($item)) {
				$variants[(string)$id] = VariantView::fromItem((string)$id, $item);
			}
		}

		return $variants;
	}
}
