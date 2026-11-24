<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Discount\DiscountService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\CurrentCustomer;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The cart of the visitor in hand.
 *
 * It holds product ids and quantities. Whether a product may be bought and
 * what it costs are asked of the catalog every time, so the cart cannot go
 * stale and nothing a browser sends sets a price.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one method per thing a
 *                                                buyer or the checkout does
 *                                                to a cart; they share the
 *                                                rules for storing it
 */
final readonly class CartService
{
	private const ATTRIBUTE_MAX_LENGTH = 200;

	private LoggerInterface $logger;

	public function __construct(
		private CartStore $store,
		private ProductCatalog $catalog,
		private CommerceSettings $settings,
		private CartRepository $carts,
		private CurrentCustomer $customer,
		private DiscountService $discounts,
		private CartPricer $pricer,
		private PaymentProviderRegistry $providers,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * Put $qty more of a product in the cart, on its existing line if it has one.
	 *
	 * @param string $variantId '' for a product without variants
	 *
	 * @throws CommerceException `product_unavailable`, `variant_required` or `variant_invalid` from the catalog;
	 *                           `qty_invalid` below 1 or above the most allowed of one item;
	 *                           `cart_full` for a new line in a full cart; `out_of_stock`
	 */
	public function add(string $productId, string $variantId, int $qty): void
	{
		$this->sync();
		$product = $this->catalog->purchasable($productId, $variantId);
		if ($qty < 1) {
			throw CommerceException::qtyInvalid($this->settings->cartMaxQty());
		}

		$items = $this->store->items();
		$key   = CartItem::lineKey($productId, $variantId);
		if (!isset($items[$key]) && count($items) >= $this->settings->cartMaxLines()) {
			throw CommerceException::cartFull($this->settings->cartMaxLines());
		}

		$this->checkPlan($product, $variantId, $items);

		$wanted = ($items[$key]->qty ?? 0) + $qty;
		$this->check($product, $variantId, $wanted);

		$items[$key] = new CartItem($productId, $variantId, $wanted);
		$this->store->put($items);
		$this->persist();
	}

	/**
	 * Set a line's quantity. 0 removes it. A line that is not there is left
	 * alone: it was removed in another tab, and that is what was wanted.
	 *
	 * @throws CommerceException as add()
	 */
	public function update(string $lineKey, int $qty): void
	{
		$this->sync();
		$items = $this->store->items();
		if (!isset($items[$lineKey])) {
			return;
		}
		if ($qty === 0) {
			$this->remove($lineKey);

			return;
		}
		if ($qty < 0) {
			throw CommerceException::qtyInvalid($this->settings->cartMaxQty());
		}

		$item = $items[$lineKey];
		$this->check($this->catalog->purchasable($item->productId, $item->variantId), $item->variantId, $qty);

		$items[$lineKey] = $item->withQty($qty);
		$this->store->put($items);
		$this->persist();
	}

	public function remove(string $lineKey): void
	{
		$this->sync();
		$items = $this->store->items();
		unset($items[$lineKey]);
		$this->store->put($items);
		$this->persist();
	}

	/** Empty the cart: its lines, what the buyer typed, the code, and its link to a saved cart. */
	public function clear(): void
	{
		$this->sync();

		// A customer's stored cart is emptied, not left behind for a new one.
		$cartId = $this->customer->get() === null ? null : $this->store->cartId();
		$this->store->clear();
		$this->store->setCartId($cartId);
		$this->persist();
	}

	/**
	 * Keep a line of text the buyer gave, such as a gift note. An empty value forgets it.
	 *
	 * @throws CommerceException `attribute_invalid` when the settings do not name $key, or the value is over 200 characters
	 */
	public function setAttribute(string $key, string $value): void
	{
		$value = trim($value);
		if (!in_array($key, $this->settings->checkoutAttributes(), true) || mb_strlen($value) > self::ATTRIBUTE_MAX_LENGTH) {
			throw CommerceException::attributeInvalid(self::ATTRIBUTE_MAX_LENGTH);
		}

		$this->sync();
		$attributes = $this->store->attributes();
		if ($value === '') {
			unset($attributes[$key]);
		} else {
			$attributes[$key] = $value;
		}
		$this->store->setAttributes($attributes);
		$this->persist();
	}

	/** @return array<string,CartItem> keyed by line key */
	public function items(): array
	{
		$this->sync();

		return $this->store->items();
	}

	/** The discount code on the cart, in capitals, or '' for none. The cart keeps the code and never an amount. */
	public function discountCode(): string
	{
		$this->sync();

		return $this->store->discountCode();
	}

	/**
	 * Check every line against the stock there is now. Advisory, like the
	 * check when a line is added: two buyers can both pass it for the last one.
	 *
	 * @throws CommerceException `out_of_stock`, or what the catalog says of a product that has gone
	 */
	public function verifyStock(): void
	{
		foreach ($this->items() as $item) {
			$this->check($this->catalog->purchasable($item->productId, $item->variantId), $item->variantId, $item->qty);
		}
	}

	/**
	 * A name for this cart that is the same every time it is checked out,
	 * so a second checkout can find the order the first one started. The
	 * cart is stored to get it, whoever the buyer is; if it cannot be
	 * stored, the session stands in.
	 */
	public function checkoutKey(): string
	{
		$this->sync();
		$customer = $this->customer->get();

		try {
			$this->write($customer['collection'] ?? '', $customer['id'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->warning('A cart could not be stored for checkout', ['error' => $e->getMessage()]);
		}

		return $this->store->cartId() ?? $this->store->sessionKey();
	}

	/** The name checkoutKey() gave this cart, or '' when it has not been checked out. */
	public function cartKey(): string
	{
		return $this->store->cartId() ?? '';
	}

	/** The cart is at checkout for this order. Never fails the request. */
	public function markCheckout(string $orderId): void
	{
		$cartId = $this->store->cartId();
		if ($cartId === null) {
			return;
		}

		try {
			$this->carts->markCheckout($cartId, $orderId);
		} catch (\Throwable $e) {
			$this->logger->warning('A cart could not be marked as at checkout', ['cartId' => $cartId, 'error' => $e->getMessage()]);
		}
	}

	/**
	 * Join this session's cart to the cart stored for a customer, and keep the
	 * result in both. Quantities of the same line are added, up to the most a
	 * cart may hold; what the buyer typed this visit wins over what was stored.
	 *
	 * Called when a customer signs in, and the first time a signed-in
	 * customer's cart is used in a session. It never fails the request: a cart
	 * that cannot be stored is still a cart.
	 */
	public function joinCustomer(string $collection, string $customerId): void
	{
		try {
			$stored = $this->carts->activeFor($collection, $customerId);
			$mine   = $this->store->items();

			$items      = $this->merge($this->storedItems($stored), $mine);
			$attributes = $this->store->attributes() + $this->storedAttributes($stored);
			$code       = $this->store->discountCode() !== '' ? $this->store->discountCode() : $this->text($stored['discountCode'] ?? '');

			$this->store->put($items);
			$this->store->setAttributes($attributes);
			$this->store->setDiscountCode($code);
			$this->store->setCartId($stored === null ? null : $this->text($stored['id'] ?? ''));
			$this->store->setOwner("{$collection}/{$customerId}");

			// Nothing to write when this session brought nothing, or there is nothing at all.
			if ($items !== [] && ($stored === null || $mine !== [] || $this->store->attributes() !== $this->storedAttributes($stored))) {
				$this->write($collection, $customerId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('A cart could not be joined to its customer', ['customer' => "{$collection}/{$customerId}", 'error' => $e->getMessage()]);
		}
	}

	/**
	 * Whether the cart holds something that is added to an account, such as
	 * a membership, while nobody is signed in to one. Checkout refuses such
	 * a cart, so a page can say so before the buyer fills in a form.
	 */
	public function needsAccount(): bool
	{
		if ($this->customer->get() !== null) {
			return false;
		}

		$grantable = $this->settings->grantableGroups();
		foreach ($this->store->items() as $item) {
			$group = $this->catalog->product($item->productId)->accessGroup ?? '';
			if ($group !== '' && in_array($group, $grantable, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The cart priced for this buyer, from the catalog as it is now. A line
	 * whose product or variant can no longer be bought is taken out of the
	 * cart and named in the notices, once.
	 */
	public function view(PricingContext $ctx): CartView
	{
		$this->sync();
		$priced  = $this->pricer->price($this->store->items(), $ctx);
		$notices = $priced['notices'];
		$lines   = $priced['lines'];
		$dropped = $notices !== [];

		// The code is worked out again every time. A cart that stopped
		// qualifying loses it, and the buyer is told why, once. An empty cart
		// keeps its code for when something is put in it again.
		$discount = null;
		$code     = $this->store->discountCode();
		if ($code !== '' && $lines !== []) {
			try {
				$discount = $this->discounts->resolveLines($code, $priced['discountLines'], $priced['subtotal'], '');
				$lines    = array_map(static fn (CartLineView $line): CartLineView => $line->withDiscount($discount->lineAmounts[$line->lineKey] ?? 0), $lines);
			} catch (CommerceException $e) {
				$this->store->setDiscountCode('');
				$notices[] = $e->getMessage();
				$dropped   = true;
			}
		}

		if ($dropped) {
			$this->store->put($priced['items']);
			$this->persist();
		}

		return new CartView($lines, $priced['count'], $priced['subtotal'], $notices, $discount);
	}

	/**
	 * Put a discount code on the cart. It is checked against the cart as it
	 * is now, and kept only if it can be used. The cart keeps the code and
	 * never an amount.
	 *
	 * @throws CommerceException `discount_invalid`, `discount_minimum` or `discount_no_items`
	 */
	public function applyDiscount(string $code, PricingContext $ctx): void
	{
		$this->sync();
		$priced = $this->pricer->price($this->store->items(), $ctx);
		// Who the buyer is is not known at the cart; checkout checks the per-customer limit.
		$applied = $this->discounts->resolveLines($code, $priced['discountLines'], $priced['subtotal'], '');

		$this->store->setDiscountCode($applied->code);
		$this->persist();
	}

	public function removeDiscount(): void
	{
		$this->sync();
		$this->store->setDiscountCode('');
		$this->persist();
	}

	/**
	 * A plan can go in the cart only when a way to pay can bill one, and
	 * only beside plans billed on the same period: a provider makes one
	 * subscription of a checkout, and a subscription has one period.
	 *
	 * @param array<string,CartItem> $items what is in the cart now
	 *
	 * @throws CommerceException `subscription_unavailable` or `subscription_interval_mismatch`
	 */
	private function checkPlan(ProductView $product, string $variantId, array $items): void
	{
		$every = $product->recurrence($product->variants[$variantId] ?? null);
		if (!$every instanceof Recurrence) {
			return;
		}
		if ($this->providers->forCart(true) === []) {
			throw CommerceException::subscriptionUnavailable();
		}

		foreach ($items as $item) {
			$other = $this->catalog->product($item->productId);
			try {
				$theirs = $other?->recurrence($other->variants[$item->variantId] ?? null);
			} catch (CommerceException) {
				continue;
			}
			if ($theirs instanceof Recurrence && $theirs != $every) {
				throw CommerceException::subscriptionIntervalMismatch();
			}
		}
	}

	/**
	 * The limits a line's quantity must keep. The stock check is a courtesy:
	 * two buyers can both pass it for the last one, and the order that is
	 * paid second is flagged then.
	 *
	 * @throws CommerceException `qty_invalid` or `out_of_stock`
	 */
	private function check(ProductView $product, string $variantId, int $wanted): void
	{
		if ($wanted > $this->settings->cartMaxQty()) {
			throw CommerceException::qtyInvalid($this->settings->cartMaxQty());
		}
		if (!$product->trackInventory || $product->allowBackorder) {
			return;
		}

		$stock = $product->hasVariants ? ($product->variants[$variantId]->stock ?? 0) : $product->stock;
		if ($stock < $wanted) {
			throw CommerceException::outOfStock(max(0, $stock));
		}
	}
	/**
	 * The first time a signed-in customer's cart is used in a session, join
	 * it to the cart stored for them. After a password or passkey login the
	 * session is new and empty, and this is where their cart comes back.
	 */
	private function sync(): void
	{
		$customer = $this->customer->get();
		if ($customer === null || $this->store->owner() === "{$customer['collection']}/{$customer['id']}") {
			return;
		}

		// Whatever cart id is in the session was not put there for this customer.
		$this->store->setCartId(null);
		$this->joinCustomer($customer['collection'], $customer['id']);
	}

	/** Write the cart through to the server when a customer is signed in. Never fails the request. */
	private function persist(): void
	{
		$customer = $this->customer->get();
		if ($customer === null) {
			return;
		}

		try {
			$this->write($customer['collection'], $customer['id']);
		} catch (\Throwable $e) {
			$this->logger->warning('A cart could not be stored', ['customer' => "{$customer['collection']}/{$customer['id']}", 'error' => $e->getMessage()]);
		}
	}

	private function write(string $collection, string $customerId): void
	{
		$this->store->setCartId($this->carts->save($this->store->cartId(), [
			'customerCollection' => $collection,
			'customerId'         => $customerId,
			'status'             => CartRepository::ACTIVE,
			'items'              => array_values(array_map(static fn (CartItem $item): array => $item->toArray(), $this->store->items())),
			'attributes'         => $this->store->attributes(),
			'discountCode'       => $this->store->discountCode(),
		]));
	}

	/**
	 * Two carts as one: quantities of the same line added, each held to the
	 * most of one item a cart may hold, and no more lines than a cart may have.
	 *
	 * @param array<string,CartItem> $stored
	 * @param array<string,CartItem> $mine
	 *
	 * @return array<string,CartItem>
	 */
	private function merge(array $stored, array $mine): array
	{
		$items = $stored;
		foreach ($mine as $key => $item) {
			$items[$key] = $item->withQty(($items[$key]->qty ?? 0) + $item->qty);
		}

		$most  = $this->settings->cartMaxQty();
		$items = array_map(static fn (CartItem $item): CartItem => $item->withQty(min($item->qty, $most)), $items);

		return array_slice($items, 0, $this->settings->cartMaxLines(), true);
	}

	/**
	 * @param array<string,mixed>|null $cart
	 *
	 * @return array<string,CartItem>
	 */
	private function storedItems(?array $cart): array
	{
		$items = [];
		foreach (is_array($cart['items'] ?? null) ? $cart['items'] : [] as $data) {
			$item = CartItem::fromArray($data);
			if ($item instanceof CartItem) {
				$items[$item->key()] = $item;
			}
		}

		return $items;
	}

	/**
	 * @param array<string,mixed>|null $cart
	 *
	 * @return array<string,string>
	 */
	private function storedAttributes(?array $cart): array
	{
		$attributes = [];
		foreach (is_array($cart['attributes'] ?? null) ? $cart['attributes'] : [] as $name => $value) {
			if (is_string($name) && is_string($value)) {
				$attributes[$name] = $value;
			}
		}

		return $attributes;
	}

	private function text(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}
}
