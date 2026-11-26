<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * The cart as the session holds it:
 *
 *   ['v' => 1, 'items' => [lineKey => [productId, variantId, qty]],
 *    'attributes' => [name => text], 'cartId' => ?string, 'discountCode' => string]
 *
 * Ids, quantities, a few lines of text the buyer typed, and a code. No
 * prices, ever: a session is the visitor's to tamper with, and everything
 * read back from it is checked.
 */
final readonly class CartStore
{
	private const VERSION = 1;

	public function __construct(private SessionInterface $session)
	{
	}

	/** @return array<string,CartItem> keyed by line key, in the order they were added */
	public function items(): array
	{
		$stored = $this->payload()['items'];
		$items  = [];
		foreach (is_array($stored) ? $stored : [] as $data) {
			$item = CartItem::fromArray($data);
			if ($item instanceof CartItem) {
				// Keyed again from the item, so a key cannot point at another line.
				$items[$item->key()] = $item;
			}
		}

		return $items;
	}

	/** @param array<CartItem> $items */
	public function put(array $items): void
	{
		$stored = [];
		foreach ($items as $item) {
			$stored[$item->key()] = $item->toArray();
		}

		$this->write(['items' => $stored]);
	}

	/** @return array<string,string> */
	public function attributes(): array
	{
		$stored     = $this->payload()['attributes'];
		$attributes = [];
		foreach (is_array($stored) ? $stored : [] as $name => $value) {
			if (is_string($name) && is_string($value)) {
				$attributes[$name] = $value;
			}
		}

		return $attributes;
	}

	/** @param array<string,string> $attributes */
	public function setAttributes(array $attributes): void
	{
		$this->write(['attributes' => $attributes]);
	}

	/** The saved cart object this session's cart belongs to, once there is one. */
	public function cartId(): ?string
	{
		$cartId = $this->payload()['cartId'];

		return is_string($cartId) && $cartId !== '' ? $cartId : null;
	}

	public function setCartId(?string $cartId): void
	{
		$this->write(['cartId' => $cartId === '' ? null : $cartId]);
	}

	/** The discount code the buyer entered, in uppercase, or '' for none. Only the code: never an amount. */
	public function discountCode(): string
	{
		$code = $this->payload()['discountCode'];

		return is_string($code) ? $code : '';
	}

	public function setDiscountCode(string $code): void
	{
		$this->write(['discountCode' => strtoupper(trim($code))]);
	}

	public function clear(): void
	{
		$this->session->set(SessionKeys::COMMERCE_CART, self::blank());
	}

	/**
	 * The customer (`collection/id`) whose stored cart this cart was last
	 * joined to, or '' for none. Kept beside the cart, not in it: emptying
	 * the cart does not change whose it is.
	 */
	public function owner(): string
	{
		$owner = $this->session->get(SessionKeys::COMMERCE_CART_OWNER);

		return is_string($owner) ? $owner : '';
	}

	public function setOwner(string $owner): void
	{
		$this->session->set(SessionKeys::COMMERCE_CART_OWNER, $owner);
	}

	/**
	 * What the session holds, with every part present. Anything that is not
	 * a cart of this version reads as an empty one.
	 *
	 * @return array{v: int, items: mixed, attributes: mixed, cartId: mixed, discountCode: mixed}
	 */
	private function payload(): array
	{
		$stored = $this->session->get(SessionKeys::COMMERCE_CART);
		if (!is_array($stored) || ($stored['v'] ?? null) !== self::VERSION) {
			return self::blank();
		}

		return [
			'v'            => self::VERSION,
			'items'        => $stored['items'] ?? [],
			'attributes'   => $stored['attributes'] ?? [],
			'cartId'       => $stored['cartId'] ?? null,
			'discountCode' => $stored['discountCode'] ?? '',
		];
	}

	/** @param array<string,mixed> $changes */
	private function write(array $changes): void
	{
		// Rebuilt from what was read and checked, so junk in the session is not carried along.
		// array_replace keeps the keys in this order whichever one changed.
		$this->session->set(SessionKeys::COMMERCE_CART, array_replace([
			'v'            => self::VERSION,
			'items'        => array_map(static fn (CartItem $item): array => $item->toArray(), $this->items()),
			'attributes'   => $this->attributes(),
			'cartId'       => $this->cartId(),
			'discountCode' => $this->discountCode(),
		], $changes));
	}

	/** @return array{v: int, items: array<never,never>, attributes: array<never,never>, cartId: null, discountCode: string} */
	private static function blank(): array
	{
		return ['v' => self::VERSION, 'items' => [], 'attributes' => [], 'cartId' => null, 'discountCode' => ''];
	}

	/**
	 * A name for this visitor's session that says nothing about it, for a
	 * cart that could not be stored. With no session there is nothing to
	 * name, so the answer is new every time and matches no other cart.
	 */
	public function sessionKey(): string
	{
		$id = (string)session_id();

		return $id === '' ? 'none-' . bin2hex(random_bytes(12)) : 'session-' . substr(hash('sha256', $id), 0, 24);
	}
}
