<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\CustomerOrders;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Commerce\Service\CheckoutService;
use TotalCMS\Domain\Commerce\Service\CommerceDashboardView;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\TemplateQuery;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * `cms.commerce`: the store's catalog and prices in a site's own templates.
 *
 * A visitor sees active products only. With the store switched off there is
 * nothing to show, and nothing here throws on a public page.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is a
 *                                                function a template calls
 *                                                as `cms.commerce.…`
 */
final readonly class CommerceTwigAdapter
{
	private const STATUS = 'status';

	/**
	 * @param \Closure(): CheckoutService $checkout lazy: checkout is only needed by a pay page
	 * @param \Closure(): TwigEngine      $twig     lazy: the engine's `cms` global holds this adapter
	 * @param \Closure(): CommerceDashboardView $dashboard lazy: only the admin home page asks
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList") one adapter answers every
	 *                                                  `cms.commerce.…` call, and each
	 *                                                  argument is a service one of them needs
	 */
	public function __construct(
		private CommerceGate $gate,
		private PaymentProviderRegistry $providers,
		private CommerceSettings $settings,
		private ProductCatalog $catalog,
		private PricingService $pricing,
		private PricingContextFactory $contexts,
		private TemplateQuery $query,
		private ActorContext $actors,
		private MoneyFilter $money,
		private CustomerOrders $orders,
		private \Closure $checkout,
		private \Closure $twig,
		private \Closure $dashboard,
	) {
	}

	/**
	 * Whether the store can sell: Commerce is on for this site, and there is
	 * at least one way to pay that is switched on and has its keys.
	 */
	public function enabled(): bool
	{
		return $this->gate->allowed() && $this->providers->enabled() !== [];
	}

	/**
	 * Whether this site has Commerce at all. True on a store that is still
	 * being set up and cannot sell yet; `enabled()` says whether it can.
	 */
	public function available(): bool
	{
		return $this->gate->allowed();
	}

	/** The store's currency, as a three-letter code. */
	public function currency(): string
	{
		return $this->settings->currency();
	}

	/**
	 * Active products, with the options of `cms.collection.query()`: filter,
	 * sort, search, limit, page. Whatever the filter says about status, only
	 * active products are listed.
	 *
	 * @param array<mixed> $options
	 *
	 * @return array{objects: array<int,array<string,mixed>>, total: int, limit: int, offset: int, page: int, pages: int, hasMore: bool}
	 */
	public function products(array $options = []): array
	{
		if (!$this->gate->allowed()) {
			return (new QueryResult([], 0, 0, 0))->forTemplate();
		}

		$asked  = is_array($options['filter'] ?? null) ? $options['filter'] : [];
		$filter = array_values(array_filter($asked, static fn (mixed $rule): bool => is_array($rule) && ($rule['property'] ?? '') !== self::STATUS));

		$options['filter'] = [...$filter, ['property' => self::STATUS, 'value' => ProductView::STATUS_ACTIVE]];

		try {
			return $this->query->collection($this->settings->collection('products'), $options)->forTemplate();
		} catch (\Throwable) {
			return (new QueryResult([], 0, 0, 0))->forTemplate();
		}
	}

	/**
	 * One product, with every field it has, or null. A visitor gets active
	 * products only; staff also get drafts and archived ones, to preview a page.
	 *
	 * @return array<string,mixed>|null
	 */
	public function product(string $id): ?array
	{
		return $this->visible($id) instanceof ProductView ? $this->catalog->object($id) : null;
	}

	/**
	 * What a product costs this buyer, or null when they cannot see it. With
	 * no variant named it is the product's own price.
	 *
	 * @return array{amount: int, listAmount: int, currency: string, formatted: string, listFormatted: string, source: string}|null
	 */
	public function price(string $productId, string $variantId = ''): ?array
	{
		$product = $this->visible($productId);
		if (!$product instanceof ProductView) {
			return null;
		}

		$variant = $product->variants[$variantId] ?? null;
		if ($variantId !== '' && ($variant === null || !$variant->active)) {
			return null;
		}

		$priced = $this->pricing->unitPrice($product, $variant, 1, $this->contexts->current());

		return [
			'amount'        => $priced->unitPrice->amount(),
			'listAmount'    => $priced->listPrice->amount(),
			'currency'      => $priced->unitPrice->currency(),
			'formatted'     => ($this->money)($priced->unitPrice->amount(), $priced->unitPrice->currency()),
			'listFormatted' => ($this->money)($priced->listPrice->amount(), $priced->listPrice->currency()),
			'source'        => $priced->source,
		];
	}

	/**
	 * Whether a product (or one variant of it) can be bought now. A product
	 * with variants and none named is in stock when any of them is.
	 */
	public function inStock(string $productId, string $variantId = ''): bool
	{
		if (!$this->gate->allowed()) {
			return false;
		}

		$product = $this->catalog->product($productId);
		if (!$product instanceof ProductView || !$product->isActive()) {
			return false;
		}

		$variants = array_filter($product->variants, static fn ($variant): bool => $variant->active);
		if ($variantId !== '') {
			$variants = array_intersect_key($variants, [$variantId => true]);
		}
		if ($product->hasVariants && $variants === []) {
			return false;
		}
		if (!$product->trackInventory || $product->allowBackorder) {
			return true;
		}

		$stock = $product->hasVariants ? max(array_map(static fn ($variant): int => $variant->stock, $variants)) : $product->stock;

		return $stock > 0;
	}

	/**
	 * One order, for someone holding its link (the `t` its pages carry).
	 * Without the order's secret, staff notes, or anything else not theirs.
	 *
	 * @return array<string,mixed>|null null when the link does not open that order
	 */
	public function order(string $orderId, string $token): ?array
	{
		return $this->orders->byLink($orderId, $token);
	}

	/**
	 * The signed-in customer's orders, newest first. None for a guest.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function orders(int $limit = 20): array
	{
		return $this->gate->allowed() ? $this->orders->mine($limit) : [];
	}

	/**
	 * The payment form for an order that is waiting to be paid on the store's
	 * own page, for a custom pay page. Nothing for any other order, and
	 * nothing without the order's signed link: what the form is mounted with
	 * belongs to that one buyer.
	 *
	 * @param array<mixed> $order the order the pay page was given
	 * @param string       $token the `token` the pay page was given
	 */
	public function embed(array $order, string $token = ''): string
	{
		$id    = $order['id'] ?? '';
		$found = is_string($id) && $id !== '' && $this->gate->allowed() ? $this->orders->opened($id, $token) : null;
		$embed = $found instanceof OrderData ? ($this->checkout)()->embedFor($found) : null;
		if (!$embed instanceof EmbeddedCheckout) {
			return '';
		}

		return ($this->twig)()->render('commerce/checkout-embedded.twig', ['embed' => ['moduleUrl' => $embed->moduleUrl, 'config' => $embed->config]]);
	}

	/**
	 * The store's numbers for the admin home page, or null. It answers only
	 * on a site with Commerce and for a signed-in user who may see orders:
	 * in a public template it is always null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function dashboard(): ?array
	{
		return ($this->dashboard)()->forCurrentUser();
	}

	/**
	 * The payment providers a buyer can choose from.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function providers(): array
	{
		if (!$this->gate->allowed()) {
			return [];
		}

		return array_map(
			static fn (PaymentProvider $provider): array => ['id' => $provider->id(), 'label' => $provider->label()],
			$this->providers->forCart(false),
		);
	}

	/** An amount in minor units written for a person, in the store currency unless another is named. */
	public function format(mixed $amount, ?string $currency = null): string
	{
		return ($this->money)($amount, $currency);
	}

	/** The product as this visitor may see it: active for anyone, any status for staff. */
	private function visible(string $id): ?ProductView
	{
		if (!$this->gate->allowed()) {
			return null;
		}

		$product = $this->catalog->product($id);
		if (!$product instanceof ProductView) {
			return null;
		}

		$staff = in_array($this->actors->current()->kind, [ActorKind::Admin, ActorKind::System], true);

		return $product->isActive() || $staff ? $product : null;
	}
}
