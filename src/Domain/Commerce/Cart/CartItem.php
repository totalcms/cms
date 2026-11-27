<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

/**
 * One thing in a cart: which product, which variant, how many. Never a
 * price. The one number a buyer may add is the amount they chose to pay
 * for a product that lets them name it; CartService checks it, and the
 * price is still worked out from the catalog.
 */
final readonly class CartItem
{
	/**
	 * @param string $variantId '' for a product without variants
	 * @param ?float $amount    what the buyer named, in major units; null for a product with a fixed price
	 */
	public function __construct(
		public string $productId,
		public string $variantId,
		public int $qty,
		public ?float $amount = null,
	) {
	}

	/**
	 * The key of the cart line for this product and variant: short, and
	 * safe in a URL or a form field whatever the ids are.
	 */
	public static function lineKey(string $productId, string $variantId): string
	{
		return substr(sha1($productId . "\0" . $variantId), 0, 12);
	}

	public function key(): string
	{
		return self::lineKey($this->productId, $this->variantId);
	}

	public function withQty(int $qty): self
	{
		return new self($this->productId, $this->variantId, $qty, $this->amount);
	}

	/** @return array{productId: string, variantId: string, qty: int, amount?: float} */
	public function toArray(): array
	{
		$data = ['productId' => $this->productId, 'variantId' => $this->variantId, 'qty' => $this->qty];
		if ($this->amount !== null) {
			$data['amount'] = $this->amount;
		}

		return $data;
	}

	/** An item read back from a session, or null when it is not one. */
	public static function fromArray(mixed $data): ?self
	{
		if (!is_array($data)) {
			return null;
		}

		$productId = $data['productId'] ?? null;
		$variantId = $data['variantId'] ?? '';
		$qty       = $data['qty'] ?? null;
		if (!is_string($productId) || $productId === '' || !is_string($variantId) || !is_int($qty) || $qty < 1) {
			return null;
		}

		// An amount that is not a usable number is dropped; the line stays.
		$amount = $data['amount'] ?? null;
		$amount = (is_int($amount) || is_float($amount)) && is_finite($amount) && $amount >= 0 ? (float)$amount : null;

		return new self($productId, $variantId, $qty, $amount);
	}
}
