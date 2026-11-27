<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Cart\CartItem;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * The slide-out cart: a dialog, the button that opens it and its count
 * badge. Each piece reloads itself when the cart changes, so a page can hold
 * any mix of them and an add button needs to know about none.
 */

const HTMX_REQUEST = ['HX-Request' => 'true'];

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c = $this->app->getContainer();
	$this->c->get(ActorContext::class)->set(Actor::anonymous());
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->add  = fn (string $productId, string $variantId = '', int $qty = 1) => postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => $variantId, 'qty' => $qty]);
	$this->open = function (): void {
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();
	};
});

describe('the drawer', function (): void {
	it('renders a dialog whose body reloads on cart-updated', function (): void {
		$html = renderTwig('{{ cms.cart.drawer({side: "left"}) }}');

		expect($html)->toContain('<dialog id="cart-drawer"')
			->toContain('data-side="left"')
			->toContain('data-open-on-add="true"')
			->toContain('id="cart-drawer-body"')
			->toContain('hx-get="/commerce/cart?part=drawer"')
			->toContain('hx-trigger="cart-updated from:body"')
			->toContain('hx-swap="innerHTML"')
			->toContain('data-cart-close')
			->toContain('Your cart')
			->toContain('href="/checkout"');
	});

	it('closes with an X button that is named for a screen reader', function (): void {
		$html = renderTwig('{{ cms.cart.drawer() }}');

		preg_match('/<button[^>]*data-cart-close[^>]*>(.*?)<\/button>/s', $html, $button);

		expect($button[0] ?? '')->toContain('aria-label="Close the cart"')
			->and($button[1] ?? '')->toContain('<svg')
			->toContain('aria-hidden="true"')
			->not->toContain('Close');
	});

	it('takes its options, and escapes them', function (): void {
		$html = renderTwig('{{ cms.cart.drawer({title: "Bag <b>", checkoutUrl: "/pay?a=1&b=2", openOnAdd: false, class: "mine", side: "sideways"}) }}');

		expect($html)->toContain('Bag &lt;b&gt;')
			->toContain('href="/pay?a=1&amp;b=2"')
			->toContain('data-open-on-add="false"')
			->toContain('class="cart-drawer mine"')
			// Anything that is not left is right.
			->toContain('data-side="right"')
			->not->toContain('<b>');
	});

	it('shows the cart inside it when the page is rendered', function (): void {
		($this->add)('tee', 'large', 2);
		($this->open)();
		$html = renderTwig('{{ cms.cart.drawer() }}');

		expect($html)->toContain('Tee &lt;i&gt;Shirt&lt;/i&gt;', '$39.98', 'class="commerce-discount');
	});

	it('returns only the drawer body for part=drawer', function (): void {
		($this->add)('tee', 'large', 2);
		$html = (string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody();

		expect($html)->toContain('Tee &lt;i&gt;Shirt&lt;/i&gt;', 'Large', '$39.98', 'name="qty"', '/commerce/cart/remove', 'class="commerce-discount')
			->and($html)->not->toContain('<dialog', 'id="cart-drawer-body"', 'id="cart-widget"');
	});

	it('says so when the cart is empty', function (): void {
		expect((string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody())->toContain('Your cart is empty.');
	});

	it('changes the cart from inside the drawer without swapping anything itself', function (): void {
		($this->add)('mug');
		$html = (string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody();

		// Its forms swap nothing: the change announces cart-updated and the body reloads itself.
		expect($html)->toContain('hx-post="/commerce/cart/update?part=none"', 'hx-post="/commerce/cart/remove?part=none"', 'hx-swap="none"');
	});
});

describe('a line at an amount the buyer named', function (): void {
	beforeEach(function (): void {
		$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectSaver::class)->saveObject('products', [
			'id' => 'give', 'title' => 'Give', 'status' => 'active', 'price' => 10, 'customPrice' => true, 'type' => 'digital',
		]));
		postJson('/commerce/cart/add', ['productId' => 'give', 'amount' => '15']);
		($this->add)('mug');
		// The lines in the order they print, each cut at the next line.
		$this->lineHtml = function (string $html, string $productId): string {
			$key   = CartItem::lineKey($productId, '');
			$start = strpos($html, 'data-line="' . $key . '"');
			expect($start)->not->toBeFalse("no line for {$productId}");
			$next = strpos($html, 'data-line="', $start + 1);

			return substr($html, $start, $next === false ? null : $next - $start);
		};
	});

	it('shows a quantity of 1 and no quantity field in the drawer', function (): void {
		$html = (string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody();

		expect(($this->lineHtml)($html, 'give'))->not->toContain('name="qty"', '/commerce/cart/update')
			->toContain('Quantity of Give: </span>1')
			->and(($this->lineHtml)($html, 'mug'))->toContain('name="qty"');
	});

	it('shows a quantity of 1 and no quantity field on the cart page', function (): void {
		$html = (string)get('/commerce/cart?part=page', HTMX_REQUEST)->getBody();

		expect(($this->lineHtml)($html, 'give'))->not->toContain('name="qty"', '/commerce/cart/update')
			->and(($this->lineHtml)($html, 'mug'))->toContain('name="qty"');
	});

	it('says where its price came from in cms.cart.lines()', function (): void {
		($this->open)();
		$sources = renderTwig('{% for line in cms.cart.lines() %}{{ line.productId }}={{ line.priceSource }};{% endfor %}');

		expect($sources)->toContain('give=custom;', 'mug=catalog;');
	});
});

describe('the toggle and its count', function (): void {
	it('renders a button for the drawer with a count that reloads', function (): void {
		($this->add)('mug', '', 2);
		($this->open)();
		$html = renderTwig('{{ cms.cart.toggle({label: "Bag"}) }}');

		expect($html)->toContain('<button type="button"', 'data-cart-toggle', 'aria-controls="cart-drawer"', 'Bag')
			->toContain('id="cart-count"', 'aria-live="polite"', 'hx-get="/commerce/cart?part=count"', 'hx-trigger="cart-updated from:body"', 'hx-swap="innerHTML"')
			->toMatch('#<span id="cart-count"[^>]*>2</span>#');
	});

	it('returns only the count for part=count', function (): void {
		($this->add)('mug', '', 2);

		expect((string)get('/commerce/cart?part=count', HTMX_REQUEST)->getBody())->toBe('2');
	});

	it('does not use up a notice meant for the cart', function (): void {
		($this->add)('mug');
		post('/commerce/cart/add?part=none', ['productId' => 'print', 'qty' => 5], HTMX_REQUEST);
		get('/commerce/cart?part=count', HTMX_REQUEST);

		expect((string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody())->toContain('This item is out of stock.');
	});
});

describe('a cart request that swaps nothing', function (): void {
	it('answers a change with nothing but the announcement', function (): void {
		$res = post('/commerce/cart/add?part=none', ['productId' => 'mug'], HTMX_REQUEST);

		expect($res->getStatusCode())->toBe(200)
			->and((string)$res->getBody())->toBe('')
			->and($res->getHeaderLine('HX-Trigger'))->toBe('cart-updated')
			->and((string)get('/commerce/cart?part=count', HTMX_REQUEST)->getBody())->toBe('1');
	});

	it('announces a refusal too, so the cart on the page can say why', function (): void {
		$res  = post('/commerce/cart/add?part=none', ['productId' => 'print', 'qty' => 5], HTMX_REQUEST);
		$body = (string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody();

		expect((string)$res->getBody())->toBe('')
			->and($res->getHeaderLine('HX-Trigger'))->toBe('cart-updated')
			->and($res->getHeaderLine('X-Commerce-Error'))->toBe('out_of_stock')
			->and($body)->toContain('This item is out of stock.')
			// Said once.
			->and((string)get('/commerce/cart?part=drawer', HTMX_REQUEST)->getBody())->not->toContain('out of stock');
	});

	it('refuses an unknown part', function (): void {
		$res = get('/commerce/cart?part=everything', HTMX_REQUEST);

		expect($res->getStatusCode())->toBe(422)->and(json_decode((string)$res->getBody(), true)['error']['code'])->toBe('part_invalid');
	});
});

describe('add buttons', function (): void {
	it('gives add buttons no target by default', function (): void {
		$plain    = renderTwig("{{ cms.cart.addButton('mug') }}");
		$targeted = renderTwig("{{ cms.cart.addButton('mug', {target: '#x'}) }}");

		expect($plain)->toContain('hx-post="/commerce/cart/add?part=none"', 'hx-swap="none"')
			->and($plain)->not->toContain('hx-target')
			->and($targeted)->toContain('hx-post="/commerce/cart/add"', 'hx-target="#x"', 'hx-swap="outerHTML"')
			->and($targeted)->not->toContain('part=none');
	});
});

describe('the commerce assets', function (): void {
	it('serves commerce.css and commerce.js and honors except', function (): void {
		expect(renderTwig('{{ cms.assetsHead() }}'))->toContain('/assets/commerce.css')
			->and(renderTwig('{{ cms.assetsBody() }}'))->toContain('/assets/commerce.js')
			->and(renderTwig('{{ cms.assetsHead({except: ["commerce"]}) }}{{ cms.assetsBody({except: ["commerce"]}) }}'))->not->toContain('commerce');
	});

	it('leaves them out on a site without Commerce', function (): void {
		bootCommerceStore(open: false);

		expect(renderTwig('{{ cms.assetsHead() }}{{ cms.assetsBody() }}'))->not->toContain('commerce')->toContain('htmx');
	});

	it('are built and shipped', function (): void {
		$assets = dirname(__DIR__, 3) . '/public/assets';

		expect(is_file($assets . '/commerce.css'))->toBeTrue()->and(is_file($assets . '/commerce.js'))->toBeTrue();
	});
});

it('renders nothing with the gate closed', function (): void {
	bootCommerceStore(open: false);

	expect(renderTwig('[{{ cms.cart.drawer() }}][{{ cms.cart.toggle() }}]'))->toBe('[][]');
});
