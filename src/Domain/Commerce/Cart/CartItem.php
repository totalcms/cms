<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

/**
 * One thing in a cart: which product, which variant, how many. Never a price.
 */
final readonly class CartItem
{
	/**
	 * @param string $variantId '' for a product without variants
	 */
	public function __construct(
		public string $productId,
		public string $variantId,
		public int $qty,
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
		return new self($this->productId, $this->variantId, $qty);
	}

	/** @return array{productId: string, variantId: string, qty: int} */
	public function toArray(): array
	{
		return ['productId' => $this->productId, 'variantId' => $this->variantId, 'qty' => $this->qty];
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

		return new self($productId, $variantId, $qty);
	}
}
