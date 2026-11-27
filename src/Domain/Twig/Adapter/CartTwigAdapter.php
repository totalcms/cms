<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Commerce\Cart\CartLineView;
use TotalCMS\Domain\Commerce\Cart\AmountField;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Cart\CartStore;
use TotalCMS\Domain\Commerce\Cart\CartView;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Discount\AppliedDiscount;
use TotalCMS\Domain\Commerce\Order\CheckoutInput;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/**
 * `cms.cart`: the visitor's cart in a site's own templates, and the forms
 * that change it.
 *
 * With the store switched off the cart is empty and every form is an empty
 * string. Nothing here throws on a public page.
 *
 * It keeps nothing between calls: the adapter outlives a request in worker
 * mode, and a cart remembered here would be shown to the next visitor.
 * What must survive from one call to the next (a notice that a product was
 * removed) is kept in the visitor's own session until it is shown.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is a
 *                                                function a template calls
 *                                                as `cms.cart.…`
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") it draws on each part
 *                                                  of the store a template
 *                                                  can show: cart, catalog,
 *                                                  providers, prices
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") it exposes the whole
 *                                                  cart to a template: the
 *                                                  cart, catalog, checkout,
 *                                                  providers and amounts
 */
final readonly class CartTwigAdapter
{
	/** The same flash the cart routes use, so a notice is shown once wherever the cart is shown next. */
	private const FLASH = 'commerce.cart';

	/**
	 * @param \Closure(): TwigEngine $twig lazy: the engine's `cms` global holds this adapter
	 */
	public function __construct(
		private CommerceGate $gate,
		private CartService $cart,
		private CartStore $store,
		private PricingContextFactory $contexts,
		private ProductCatalog $catalog,
		private SessionInterface $session,
		private CSRFTokenManager $csrf,
		private Config $config,
		private \Closure $twig,
		private PaymentProviderRegistry $providers,
		private MoneyFilter $money,
		private TaxCalculator $tax,
		private \Closure $checkout,
		private AmountField $amount,
	) {
	}

	/** How many items are in the cart, counting quantities. */
	public function count(): int
	{
		return $this->view()->count ?? 0;
	}

	public function isEmpty(): bool
	{
		return $this->view()?->isEmpty() ?? true;
	}

	/**
	 * The cart's lines. Every amount is a whole number of minor units of `currency`.
	 *
	 * @return list<array{lineKey: string, productId: string, variantId: string, title: string, variantTitle: string, image: string, qty: int, unitPrice: int, lineTotal: int, discountTotal: int, currency: string, url: string, billing: string, interval: string, intervalCount: int, trialDays: int, priceSource: string}>
	 */
	public function lines(): array
	{
		return array_map(fn (CartLineView $line): array => [
			'lineKey'       => $line->lineKey,
			'productId'     => $line->productId,
			'variantId'     => $line->variantId,
			'title'         => $line->title,
			'variantTitle'  => $line->variantTitle,
			'image'         => $line->image,
			'qty'           => $line->qty,
			'unitPrice'     => $line->unitPrice->amount(),
			'lineTotal'     => $line->lineTotal->amount(),
			'discountTotal' => $line->discountTotal,
			'currency'      => $line->lineTotal->currency(),
			'url'           => $this->catalog->url($line->productId),
			// How the line is billed: `once`, or `recurring` every `intervalCount` `interval`s.
			'billing'       => $line->billing,
			'interval'      => $line->interval,
			'intervalCount' => $line->intervalCount,
			'trialDays'     => $line->trialDays,
			// `custom` for an amount the buyer named: a line of one, with no quantity to change.
			'priceSource'   => $line->priceSource,
		], $this->view()->lines ?? []);
	}

	/** Whether the cart holds a plan: something billed again every period. */
	public function hasSubscription(): bool
	{
		return $this->view()?->hasSubscription() ?? false;
	}

	/**
	 * The ways to pay that can take this cart, from one look at it.
	 *
	 * @return list<PaymentProvider>
	 */
	public function payableWith(): array
	{
		$view = $this->view();

		return $this->providers->forCart($view?->hasSubscription() ?? false, $view?->hasNamedPlan() ?? false);
	}

	/** Whether a plan in the cart is at an amount the buyer named. */
	public function hasNamedPlan(): bool
	{
		return $this->view()?->hasNamedPlan() ?? false;
	}

	/** The lines added up, in minor units, before discount, shipping and tax. */
	public function subtotal(): int
	{
		return $this->view()?->subtotal->amount() ?? 0;
	}

	/**
	 * The discount code on the cart, worked out against it now, or null for
	 * none. `amount` is in minor units; it is 0 for a free-shipping code.
	 *
	 * @return array{code: string, type: string, amount: int, formatted: string}|null
	 */
	public function discount(): ?array
	{
		$view = $this->view();
		if (!$view instanceof CartView || !$view->discount instanceof AppliedDiscount) {
			return null;
		}

		return [
			'code'      => $view->discount->code,
			'type'      => $view->discount->type,
			'amount'    => $view->discount->amount,
			'formatted' => ($this->money)($view->discountTotal->amount(), $view->discountTotal->currency()),
		];
	}

	/** The subtotal less the discount, in minor units, before shipping and tax. */
	public function total(): int
	{
		return $this->view()?->total->amount() ?? 0;
	}

	/** The discount code field, or the code on the cart with a button to take it off. */
	public function discountForm(): string
	{
		return $this->partial('commerce/discount-form.twig');
	}

	/**
	 * What changed since the buyer last looked, such as a product that was
	 * removed. Each is given once: print them where the buyer will see them.
	 *
	 * @return list<string>
	 */
	public function notices(): array
	{
		if (!$this->view() instanceof CartView) {
			return [];
		}

		return array_values(array_filter($this->session->getFlash()->get(self::FLASH), is_string(...)));
	}

	/** A line of text the buyer gave at the cart, such as a gift note, or ''. */
	public function attribute(string $key): string
	{
		return $this->gate->allowed() ? ($this->store->attributes()[$key] ?? '') : '';
	}

	/**
	 * An "Add to cart" button for a product.
	 *
	 * @param array<mixed> $options variant, qty, label, class, target (a selector to swap the cart widget
	 *                              into; without one the form swaps nothing and the carts on the page reload)
	 */
	public function addButton(string $productId, array $options = []): string
	{
		$variantId = $this->option($options, 'variant');
		$product   = $this->buyable($productId);
		if (!$product instanceof ProductView || !$this->fits($product, $variantId)) {
			return '';
		}

		return $this->form($product, $options, ['variantId' => $variantId, 'variants' => [], 'showQty' => false]);
	}

	/**
	 * An add form with a choice of the product's variants and a quantity.
	 * For a product without variants it is the form without the choice.
	 *
	 * @param array<mixed> $options qty, label, target, class
	 */
	public function variantPicker(string $productId, array $options = []): string
	{
		$product = $this->buyable($productId);
		if (!$product instanceof ProductView) {
			return '';
		}

		$variants = array_map(
			static fn (VariantView $variant): array => ['id' => $variant->id, 'title' => $variant->title],
			array_values(array_filter($product->variants, static fn (VariantView $variant): bool => $variant->active)),
		);
		if ($product->hasVariants && $variants === []) {
			return '';
		}

		return $this->form($product, $options, ['variantId' => '', 'variants' => $variants, 'showQty' => true]);
	}

	/** The mini cart (`id="cart-widget"`). It reloads itself when the cart changes. */
	public function widget(): string
	{
		return $this->partial('commerce/cart-widget.twig');
	}

	/**
	 * The slide-out cart (`<dialog id="cart-drawer">`). Put it once on a
	 * page, with `cms.cart.toggle()` wherever the button belongs. It needs
	 * the `commerce` front-end assets, which `cms.assetsHead()` and
	 * `cms.assetsBody()` include.
	 *
	 * @param array<mixed> $options side (right | left), title, checkoutUrl, openOnAdd (default true), class
	 */
	public function drawer(array $options = []): string
	{
		return $this->partial('commerce/cart-drawer.twig', [
			'side'        => $this->option($options, 'side') === 'left' ? 'left' : 'right',
			'title'       => $this->option($options, 'title') ?: 'Your cart',
			'checkoutUrl' => $this->option($options, 'checkoutUrl') ?: '/checkout',
			'openOnAdd'   => ($options['openOnAdd'] ?? true) !== false,
			'class'       => $this->option($options, 'class'),
		]);
	}

	/**
	 * The button that opens the drawer, with the count of what is in the cart.
	 *
	 * @param array<mixed> $options label (default "Cart"), class
	 */
	public function toggle(array $options = []): string
	{
		return $this->partial('commerce/cart-toggle.twig', [
			'label' => $this->option($options, 'label') ?: 'Cart',
			'class' => $this->option($options, 'class'),
		], notices: false);
	}

	/** The full cart, with a quantity and a remove button on every line (`id="cart-page"`). */
	public function page(): string
	{
		return $this->partial('commerce/cart-page.twig');
	}

	/**
	 * The checkout form: the buyer's email, where it ships if anything does,
	 * and how they pay. Nothing for an empty cart, or one nobody can pay for.
	 *
	 * A cart that needs an account its visitor is not signed in to gets a
	 * note saying so in its place, with a link to sign in.
	 *
	 * @param array<mixed> $options label (the button's text), class, registerUrl (the site's page for
	 *                              making an account, linked from that note)
	 */
	public function checkoutForm(array $options = []): string
	{
		$view = $this->view();
		if (!$view instanceof CartView || $view->lines === []) {
			return '';
		}

		// Something in the cart is added to an account, and nobody is signed
		// in to one. Checkout would refuse, so the buyer is told before they
		// fill anything in.
		if ($this->cart->needsAccount()) {
			$customers = $this->config->commerce['customerCollection'] ?? '';

			return ($this->twig)()->render('commerce/checkout-account.twig', [
				'collection'  => is_string($customers) ? $customers : '',
				'registerUrl' => $this->option($options, 'registerUrl'),
				'class'       => $this->option($options, 'class'),
			]);
		}

		$providers = array_map(
			fn (PaymentProvider $provider): array => ['id' => $provider->id(), 'label' => $provider->label(), 'worksOutTax' => $this->providers->worksOutTax($provider)],
			$this->providers->forCart($view->hasSubscription(), $view->hasNamedPlan()),
		);
		// The store works out the tax for an order paid with any provider
		// that does not work it out itself, and for one that costs nothing.
		$storeTaxes = $this->tax->mode() !== TaxCalculator::NONE && array_filter($providers, static fn (array $provider): bool => !$provider['worksOutTax']) !== [];
		// A cart that costs nothing needs no way to pay, whether its products
		// are free or a discount code took the whole price off.
		if ($providers === [] && !$view->total->isZero()) {
			return '';
		}

		return ($this->twig)()->render('commerce/checkout-form.twig', [
			'quote'     => $this->quote($providers[0]['id'] ?? ''),
			'providers' => $providers,
			'ships'     => $ships = $this->ships($view),
			// The store's tax goes by where the buyer is. An order that ships
			// says so with its address; one that does not has to be asked.
			'taxed'     => !$ships && ($storeTaxes || ($providers === [] && $this->tax->mode() !== TaxCalculator::NONE)),
			'email'     => '',
			'label'     => $this->option($options, 'label') ?: 'Continue to payment',
			'class'     => $this->option($options, 'class'),
			'base'      => $this->config->api,
			'csrf'      => $this->csrf->getTokenField(),
		]);
	}

	/**
	 * What the cart comes to before the buyer has said anything, for the
	 * form to start with: the way to pay that is chosen first, and no
	 * address. Null when it cannot be worked out, and the form shows none.
	 *
	 * @return array<string,bool|int|string>|null
	 */
	private function quote(string $provider): ?array
	{
		try {
			$input = CheckoutInput::fromRequest(['provider' => $provider, 'attributes' => $this->store->attributes()], []);

			return ($this->checkout)()->quote($input, $this->contexts->current($input->attributes))->toArray();
		} catch (\Throwable) {
			return null;
		}
	}

	/** Whether anything in the cart has to be sent somewhere. */
	private function ships(CartView $view): bool
	{
		foreach ($view->lines as $line) {
			if ($this->catalog->product($line->productId)?->requiresShipping === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The cart priced for this visitor, or null when the store is off.
	 * Notices go into the session as they appear, so a template that reads
	 * the count first still has them to show.
	 */
	private function view(): ?CartView
	{
		if (!$this->gate->allowed()) {
			return null;
		}

		$view = $this->cart->view($this->contexts->current($this->store->attributes()));
		foreach ($view->notices as $notice) {
			$this->session->getFlash()->add(self::FLASH, $notice);
		}

		return $view;
	}

	/**
	 * One of the cart's partials, rendered with the cart as it is now.
	 *
	 * @param array<string,mixed> $with    what this partial takes beside the cart
	 * @param bool                $notices false for a partial with nowhere to print a notice: reading one uses it up
	 */
	private function partial(string $template, array $with = [], bool $notices = true): string
	{
		$view = $this->view();
		if (!$view instanceof CartView) {
			return '';
		}

		return ($this->twig)()->render($template, $with + [
			'cart'    => $view,
			'notices' => $notices ? array_values(array_filter($this->session->getFlash()->get(self::FLASH), is_string(...))) : [],
			'base'    => $this->config->api,
			'csrf'    => $this->csrf->getTokenField(),
		]);
	}

	/**
	 * @param array<mixed>        $options
	 * @param array<string,mixed> $with
	 */
	private function form(ProductView $product, array $options, array $with): string
	{
		$qty = $options['qty'] ?? 1;

		// A named price is one line of one: no quantity, whatever was asked.
		if ($product->customPrice) {
			$qty  = 1;
			$with = ['showQty' => false] + $with;
		}

		return ($this->twig)()->render('commerce/add-form.twig', $this->amount->variables($product) + $with + [
			'productId' => $product->id,
			'qty'       => is_int($qty) && $qty > 0 ? $qty : 1,
			'label'     => $this->option($options, 'label') ?: 'Add to cart',
			// No target: the form swaps nothing, and every cart on the page reloads itself.
			'target'    => $this->option($options, 'target'),
			'class'     => $this->option($options, 'class'),
			'base'      => $this->config->api,
			'csrf'      => $this->csrf->getTokenField(),
		]);
	}

	/** The product, when the store is on and it is for sale. */
	private function buyable(string $productId): ?ProductView
	{
		if (!$this->gate->allowed()) {
			return null;
		}

		$product = $this->catalog->product($productId);

		return $product instanceof ProductView && $product->isActive() ? $product : null;
	}

	/** Whether a button for this variant (or for none) can add anything. */
	private function fits(ProductView $product, string $variantId): bool
	{
		if ($variantId === '') {
			return true;
		}

		return isset($product->variants[$variantId]) && $product->variants[$variantId]->active;
	}

	/** @param array<mixed> $options */
	private function option(array $options, string $key): string
	{
		$value = $options[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
