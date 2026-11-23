<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * One line of an order being built: the product as it was sold, at the
 * price it was sold at. Every amount is minor units of the order's currency.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every
 *                                                  field is one fact of the
 *                                                  line, named
 */
final readonly class OrderLineDraft
{
	public const ONCE      = 'once';
	public const RECURRING = 'recurring';

	/**
	 * @param string              $title       the product's title, with the variant's when there is one
	 * @param string              $type        physical | digital
	 * @param int                 $unitPrice   what is charged per unit, after price resolvers
	 * @param int                 $listPrice   the catalog's price per unit
	 * @param string              $priceSource 'catalog', or the id of the resolver that set the price
	 * @param array<string,mixed> $meta        a copy of the product's `meta`
	 * @param array<string,mixed> $metadata    written by order-building listeners
	 * @param int                 $discountTotal this line's share of the discount
	 * @param string              $billing     once | recurring
	 */
	public function __construct(
		public string $lineId,
		public string $productId,
		public string $variantId,
		public string $sku,
		public string $title,
		public string $type,
		public int $qty,
		public int $unitPrice,
		public int $listPrice,
		public string $priceSource,
		public int $lineTotal,
		public bool $taxable,
		public bool $requiresShipping,
		public int $taxTotal = 0,
		public array $meta = [],
		public array $metadata = [],
		public string $category = '',
		public int $discountTotal = 0,
		public string $billing = self::ONCE,
		public string $interval = '',
		public int $intervalCount = 0,
		public int $trialDays = 0,
		public string $accessGroup = '',
	) {
	}

	/** What is charged for the line: its total less its share of the discount. */
	public function netTotal(): int
	{
		return $this->lineTotal - $this->discountTotal;
	}

	/** Whether the line is a thing that has to be sent. */
	public function ships(): bool
	{
		return $this->requiresShipping && $this->type === 'physical';
	}

	public function isRecurring(): bool
	{
		return $this->billing === self::RECURRING;
	}

	public function withDiscount(int $discountTotal): self
	{
		return $this->with(['discountTotal' => $discountTotal]);
	}

	public function withTax(int $taxTotal): self
	{
		return $this->with(['taxTotal' => $taxTotal]);
	}

	/** @param array<string,mixed> $metadata */
	public function withMetadata(array $metadata): self
	{
		return $this->with(['metadata' => $metadata]);
	}

	/**
	 * The line as it is stored on the order. `id` is the line's key in the
	 * order's lines, which the deck requires each item to repeat.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$line = get_object_vars($this);
		unset($line['lineId']);

		return ['id' => $this->lineId] + $line;
	}

	/** @param array<string,mixed> $changes */
	private function with(array $changes): self
	{
		return new self(...array_merge(get_object_vars($this), $changes));
	}
}
