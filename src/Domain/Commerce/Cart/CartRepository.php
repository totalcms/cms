<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\FileLock;

/**
 * The carts kept on the server: a signed-in customer's cart, and any cart
 * that reached checkout. Like the session cart, a stored cart holds ids and
 * quantities and never a price.
 *
 * Every write is the store's own, made as the system, and a change to an
 * existing cart is made under a lock on that cart.
 */
final readonly class CartRepository
{
	public const ACTIVE    = 'active';
	public const CHECKOUT  = 'checkout';
	public const CONVERTED = 'converted';

	public function __construct(
		private CommerceSettings $settings,
		private ObjectFetcher $objects,
		private ObjectSaver $saver,
		private ObjectUpdater $updater,
		private IndexReader $index,
		private FileLock $lock,
		private ActorContext $actors,
	) {
	}

	/**
	 * The customer's active cart, newest first if they somehow have two, or
	 * null when they have none.
	 *
	 * @return array<string,mixed>|null
	 */
	public function activeFor(string $collection, string $customerId): ?array
	{
		$carts = $this->collection();
		$ids   = $this->index->fetchIndex($carts)->objects
			->filter(static fn (array $row): bool => ($row['customerId'] ?? '') === $customerId && ($row['status'] ?? '') === self::ACTIVE)
			->sortByDesc(static fn (array $row): string => (string)($row['updated'] ?? ''))
			->pluck('id')
			->all();

		foreach ($ids as $id) {
			$cart = $this->find((string)$id);
			// The index does not hold the collection, and two auth collections may share a user id.
			if ($cart !== null && ($cart['customerCollection'] ?? '') === $collection && ($cart['status'] ?? '') === self::ACTIVE) {
				return $cart;
			}
		}

		return null;
	}

	/**
	 * Write a cart: a new one when $cartId is null or names no cart, otherwise
	 * the fields given onto the cart that is there.
	 *
	 * @param array<string,mixed> $fields any of customerCollection, customerId, email, items, attributes, discountCode, status, orderId
	 *
	 * @return string the cart's id
	 */
	public function save(?string $cartId, array $fields): string
	{
		$carts  = $this->collection();
		$fields = ['updated' => date(DATE_ATOM)] + $fields;
		unset($fields['id']);

		return $this->actors->runAs(Actor::system(), function () use ($carts, $cartId, $fields): string {
			if ($cartId === null || $cartId === '' || !$this->objects->existsObject($carts, $cartId)) {
				return $this->saver->saveObject($carts, ['status' => self::ACTIVE] + $fields)->id;
			}

			return $this->lock->withLock("{$carts}/{$cartId}", function () use ($carts, $cartId, $fields): string {
				$stored = $this->objects->fetchObjectFresh($carts, $cartId)->toArray();
				$this->updater->updateObject($carts, $cartId, $fields + $stored);

				return $cartId;
			});
		});
	}

	/** The cart is at checkout for this order: it is no longer the customer's active cart. */
	public function markCheckout(string $cartId, string $orderId): void
	{
		$this->save($cartId, ['status' => self::CHECKOUT, 'orderId' => $orderId]);
	}

	/** The cart became a paid order. */
	public function markConverted(string $cartId): void
	{
		$this->save($cartId, ['status' => self::CONVERTED]);
	}

	/** @return array<string,mixed>|null */
	private function find(string $cartId): ?array
	{
		$carts = $this->collection();

		return $cartId !== '' && $this->objects->existsObject($carts, $cartId) ? $this->objects->fetchObjectFresh($carts, $cartId)->toArray() : null;
	}

	private function collection(): string
	{
		return $this->settings->collection('carts');
	}
}
