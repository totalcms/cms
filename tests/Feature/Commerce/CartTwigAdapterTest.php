<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/*
 * `cms.cart` in a site's own templates: what is in the visitor's cart, and
 * the forms that change it. With the store switched off it is all empty,
 * and never an error on a public page.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c    = $this->app->getContainer();
	$this->cart = $this->c->get(CartService::class);
	$this->twig = fn (string $template): string => $this->c->get(TwigEngine::class)->renderString($template, []);
});

describe('reading the cart', function (): void {
	test('counts what is in it', function (): void {
		expect(($this->twig)('{{ cms.cart.count() }}|{{ cms.cart.isEmpty() ? "empty" : "has items" }}|{{ cms.cart.subtotal() }}'))->toBe('0|empty|0');

		$this->cart->add('tee', 'large', 2);

		expect(($this->twig)('{{ cms.cart.count() }}|{{ cms.cart.isEmpty() ? "empty" : "has items" }}|{{ cms.cart.subtotal() }}|{{ cms.cart.subtotal()|money }}'))->toBe('2|has items|3998|$39.98');
	});

	test('lists its lines, with every amount in minor units', function (): void {
		$this->cart->add('tee', 'large', 2);
		$this->cart->add('mug', '', 1);

		$out = ($this->twig)('{% for line in cms.cart.lines() %}{{ line.productId }}/{{ line.variantId }}/{{ line.variantTitle }}/{{ line.qty }}/{{ line.unitPrice }}/{{ line.lineTotal }}/{{ line.currency }}/{{ line.lineKey|length }};{% endfor %}');

		expect($out)->toBe('tee/large/Large/2/1999/3998/USD/12;mug///1/1250/1250/USD/12;');
	});

	test('says once that a product was taken out of the cart', function (): void {
		$this->cart->add('mug', '', 1);
		$mug = $this->c->get(ObjectFetcher::class)->fetchObject('products', 'mug')->toArray();
		$this->c->get(ObjectUpdater::class)->updateObject('products', 'mug', ['status' => 'archived'] + $mug);

		// The count is read first, as a page header does; the notice must still be there to show.
		$out = ($this->twig)('{{ cms.cart.count() }}|{% for notice in cms.cart.notices() %}{{ notice }}{% endfor %}|{{ cms.cart.notices()|length }}');

		expect($out)->toBe('0|Mug is no longer available and was removed from your cart.|0');
	});

	test('reads a checkout attribute the buyer gave', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['checkoutAttributes' => ['giftNote']] + $config->commerce;
		$this->cart->setAttribute('giftNote', 'Happy birthday');

		expect(($this->twig)("{{ cms.cart.attribute('giftNote') }}|{{ cms.cart.attribute('nothing') }}|"))->toBe('Happy birthday||');
	});
});

describe('the forms', function (): void {
	test('addButton() is a form that posts to the cart, for HTMX and without it', function (): void {
		$html = ($this->twig)("{{ cms.cart.addButton('mug') }}");

		expect($html)->toContain('<form', 'method="post"', 'action="/commerce/cart/add"', 'hx-post="/commerce/cart/add?part=none"', 'hx-swap="none"', 'name="csrf_token"', 'name="productId" value="mug"', 'Add to cart')
			->and($html)->not->toContain('<select');
	});

	test('addButton() takes a variant, a quantity, a label, a target and a class', function (): void {
		$html = ($this->twig)("{{ cms.cart.addButton('tee', {variant: 'large', qty: 3, label: 'Buy <now>', target: '#mini', class: 'big \"btn'}) }}");

		expect($html)->toContain('name="variantId" value="large"', 'name="qty" value="3"', 'Buy &lt;now&gt;', 'hx-target="#mini"')
			->and($html)->not->toContain('Buy <now>')
			->and($html)->not->toContain('class="big "btn');
	});

	test('variantPicker() offers the variants that can be bought', function (): void {
		$html = ($this->twig)("{{ cms.cart.variantPicker('tee') }}");

		expect($html)->toContain('<select name="variantId"', '<option value="large">Large</option>', '<option value="small">Small</option>', 'name="qty"', 'name="productId" value="tee"')
			->and($html)->not->toContain('Gone');
	});

	test('a form for something that cannot be bought is nothing at all', function (string $call): void {
		expect(($this->twig)("[{{ cms.cart.{$call} }}]"))->toBe('[]');
	})->with(["addButton('nothing')", "addButton('draft-thing')", "variantPicker('draft-thing')", "addButton('tee', {variant: 'gone'})", "addButton('mug', {variant: 'large'})"]);

	test('widget() and page() are the cart partials the routes answer with', function (): void {
		$this->cart->add('tee', 'large', 1);

		$widget = ($this->twig)('{{ cms.cart.widget() }}');
		$page   = ($this->twig)('{{ cms.cart.page() }}');

		expect($widget)->toContain('id="cart-widget"', 'hx-trigger="cart-updated from:body"', 'Tee &lt;i&gt;Shirt&lt;/i&gt;', '$19.99')
			->and($page)->toContain('id="cart-page"', 'hx-post="/commerce/cart/update?part=page"', 'name="csrf_token"', '$19.99');
	});
	// As in the slide-out cart: a quantity saves itself when it changes, and
	// the Update button is only for a browser with no JavaScript.
	test('a quantity on the cart page saves itself when it is changed', function (): void {
		$this->cart->add('tee', 'large', 1);

		$page = ($this->twig)('{{ cms.cart.page() }}');

		expect($page)->toContain('hx-post="/commerce/cart/update?part=page" hx-target="#cart-page" hx-swap="outerHTML" hx-trigger="change, submit"')
			->toContain('<noscript><button type="submit">Update</button></noscript>');
	});
});

test('with the store switched off, the cart is empty and every form is nothing', function (): void {
	bootCommerceStore(open: false);
	$this->app->getContainer()->get(CartService::class)->add('mug', '', 2);

	$out = renderTwig("{{ cms.cart.count() }}|{{ cms.cart.isEmpty() ? 'empty' : 'has items' }}|{{ cms.cart.lines()|length }}|{{ cms.cart.subtotal() }}|{{ cms.cart.notices()|length }}|{{ cms.cart.attribute('giftNote') }}|[{{ cms.cart.addButton('mug') }}{{ cms.cart.variantPicker('tee') }}{{ cms.cart.widget() }}{{ cms.cart.page() }}]");

	expect($out)->toBe('0|empty|0|0|0||[]');
});
