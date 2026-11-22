<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Commerce\Cart\CartLineView;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Cart\CartStore;
use TotalCMS\Domain\Commerce\Cart\CartView;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
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
	 * @return list<array{lineKey: string, productId: string, variantId: string, title: string, variantTitle: string, image: string, qty: int, unitPrice: int, lineTotal: int, currency: string, url: string}>
	 */
	public function lines(): array
	{
		return array_map(fn (CartLineView $line): array => [
			'lineKey'      => $line->lineKey,
			'productId'    => $line->productId,
			'variantId'    => $line->variantId,
			'title'        => $line->title,
			'variantTitle' => $line->variantTitle,
			'image'        => $line->image,
			'qty'          => $line->qty,
			'unitPrice'    => $line->unitPrice->amount(),
			'lineTotal'    => $line->lineTotal->amount(),
			'currency'     => $line->lineTotal->currency(),
			'url'          => $this->catalog->url($line->productId),
		], $this->view()->lines ?? []);
	}

	/** The lines added up, in minor units, before discount, shipping and tax. */
	public function subtotal(): int
	{
		return $this->view()?->subtotal->amount() ?? 0;
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
	 * @param array<mixed> $options variant, qty, label, target (the selector to swap, default `#cart-widget`), class
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

	/** The full cart, with a quantity and a remove button on every line (`id="cart-page"`). */
	public function page(): string
	{
		return $this->partial('commerce/cart-page.twig');
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

	private function partial(string $template): string
	{
		$view = $this->view();
		if (!$view instanceof CartView) {
			return '';
		}

		return ($this->twig)()->render($template, [
			'cart'    => $view,
			'notices' => array_values(array_filter($this->session->getFlash()->get(self::FLASH), is_string(...))),
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

		return ($this->twig)()->render('commerce/add-form.twig', $with + [
			'productId' => $product->id,
			'qty'       => is_int($qty) && $qty > 0 ? $qty : 1,
			'label'     => $this->option($options, 'label') ?: 'Add to cart',
			'target'    => $this->option($options, 'target') ?: '#cart-widget',
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
