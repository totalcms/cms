<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A buyer types a code at the cart. The cart keeps the code and nothing
 * else: what it takes off is worked out every time the cart is shown, so a
 * cart that stops qualifying stops being discounted.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c       = $this->app->getContainer();
	$this->session = $this->c->get(PhpSession::class);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->add  = fn (string $productId, string $variantId = '', int $qty = 1) => postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => $variantId, 'qty' => $qty]);
	$this->json = fn ($response): array => json_decode((string)$response->getBody(), true) ?? [];
	$this->cart = fn (): array => ($this->json)(get('/commerce/cart', ['Accept' => 'application/json']));
	$this->kept = fn (): array => (array)$this->session->get(SessionKeys::COMMERCE_CART);
	$this->open = function (): void {
		$this->session->isStarted() || $this->session->start();
	};

	seedDiscount('fall15', ['type' => 'percent', 'value' => 15]);
	($this->add)('tee', 'large');
	($this->add)('mug');
});

it('applies a code and keeps only the code in the session', function (): void {
	$res  = postJson('/commerce/cart/discount', ['code' => 'fall15', 'amount' => 3249, 'discount' => 3249]);
	$cart = ($this->json)($res);

	expect($res->getStatusCode())->toBe(200)
		->and($cart['discount'])->toBe(['code' => 'FALL15', 'type' => 'percent', 'amount' => 487])
		->and($cart['subtotal'])->toBe(3249)
		->and($cart['total'])->toBe(2762)
		->and(($this->kept)()['discountCode'])->toBe('FALL15')
		->and(json_encode(($this->kept)()))->not->toContain('487', '2762');
});

it('shows each line its share', function (): void {
	applyCode('fall15');

	expect(array_column(($this->cart)()['lines'], 'discountTotal'))->toBe([300, 187]);
});

it('has no discount, and a total equal to the subtotal, without a code', function (): void {
	$cart = ($this->cart)();

	expect($cart['discount'])->toBeNull()->and($cart['total'])->toBe(3249)->and(array_column($cart['lines'], 'discountTotal'))->toBe([0, 0]);
});

it('answers an unknown and an expired code identically', function (): void {
	seedDiscount('old-code', ['endsAt' => '2020-01-01T00:00:00Z']);
	$unknown = postJson('/commerce/cart/discount', ['code' => 'no-such-code']);
	$expired = postJson('/commerce/cart/discount', ['code' => 'old-code']);

	expect($unknown->getStatusCode())->toBe(422)
		->and($expired->getStatusCode())->toBe(422)
		->and((string)$unknown->getBody())->toBe((string)$expired->getBody())
		->and(($this->json)($unknown)['error'])->toBe(['code' => 'discount_invalid', 'message' => 'That code is not valid.'])
		->and(($this->kept)()['discountCode'] ?? '')->toBe('');
});

it('keeps the code that was already there when a new one is refused', function (): void {
	applyCode('fall15');
	postJson('/commerce/cart/discount', ['code' => 'no-such-code']);

	expect(($this->cart)()['discount']['code'])->toBe('FALL15');
});

it('limits code attempts to ten a minute per IP', function (): void {
	$statuses = [];
	for ($i = 0; $i < 11; $i++) {
		$statuses[] = postJson('/commerce/cart/discount', ['code' => "guess-{$i}"])->getStatusCode();
	}

	expect(array_slice($statuses, 0, 10))->toBe(array_fill(0, 10, 422))->and($statuses[10])->toBe(429);
});

it('drops a code the cart no longer qualifies for', function (): void {
	seedDiscount('big30', ['minSubtotal' => 30]);
	applyCode('big30');
	$before = ($this->cart)();
	$tee    = $before['lines'][0]['lineKey'];
	// The answer to the change that broke the code is the first to show the cart, so it carries the news.
	$after = ($this->json)(postJson('/commerce/cart/remove', ['lineKey' => $tee]));

	expect($before['discount']['code'])->toBe('BIG30')
		->and($after['discount'])->toBeNull()
		->and($after['total'])->toBe(1250)
		->and($after['notices'])->toBe(['This code needs a subtotal of $30.00.'])
		->and(($this->kept)()['discountCode'])->toBe('')
		// Said once.
		->and(($this->cart)()['notices'])->toBe([]);
});

it('keeps a code on a cart that was emptied, for when it is filled again', function (): void {
	applyCode('fall15');
	foreach (($this->cart)()['lines'] as $line) {
		postJson('/commerce/cart/remove', ['lineKey' => $line['lineKey']]);
	}
	$empty = ($this->cart)();
	($this->add)('mug');

	expect($empty['discount'])->toBeNull()->and($empty['notices'])->toBe([])->and(($this->cart)()['discount']['code'])->toBe('FALL15');
});

it('removes a code', function (): void {
	applyCode('fall15');
	$res = postJson('/commerce/cart/discount/remove', []);

	expect($res->getStatusCode())->toBe(200)
		->and(($this->json)($res)['discount'])->toBeNull()
		->and(($this->json)($res)['total'])->toBe(3249)
		->and(($this->kept)()['discountCode'])->toBe('');
});

it('triggers cart-updated for HTMX', function (): void {
	$apply  = post('/commerce/cart/discount', ['code' => 'fall15'], ['HX-Request' => 'true']);
	$remove = post('/commerce/cart/discount/remove', [], ['HX-Request' => 'true']);
	$bad    = post('/commerce/cart/discount', ['code' => 'nope-nope'], ['HX-Request' => 'true']);

	expect($apply->getHeaderLine('HX-Trigger'))->toBe('cart-updated')
		->and($remove->getHeaderLine('HX-Trigger'))->toBe('cart-updated')
		->and($bad->hasHeader('HX-Trigger'))->toBeFalse()
		->and($bad->getHeaderLine('X-Commerce-Error'))->toBe('discount_invalid')
		->and((string)$bad->getBody())->toContain('That code is not valid.');
});

it('answers the code form with the code form', function (): void {
	$applied = (string)post('/commerce/cart/discount?part=discount', ['code' => 'fall15'], ['HX-Request' => 'true'])->getBody();
	$refused = (string)post('/commerce/cart/discount?part=discount', ['code' => 'nope-nope'], ['HX-Request' => 'true'])->getBody();

	expect($applied)->toContain('class="commerce-discount', 'FALL15', '/commerce/cart/discount/remove')
		->and($applied)->not->toContain('id="cart-widget"')
		->and($refused)->toContain('class="commerce-discount', 'That code is not valid.');
});

it('shows the discount and the total on the cart page and in the widget', function (): void {
	applyCode('fall15');
	$page   = (string)get('/commerce/cart?part=page', ['HX-Request' => 'true'])->getBody();
	$widget = (string)get('/commerce/cart?part=widget', ['HX-Request' => 'true'])->getBody();

	expect($page)->toContain('FALL15', '$4.87', '$27.62', '$32.49', 'class="commerce-discount')
		->and($widget)->toContain('FALL15', '$27.62');
});

it('is closed when the store is', function (): void {
	bootCommerceStore(open: false);
	withHeader('X-CSRF-Token', $this->app->getContainer()->get(CSRFTokenManager::class)->getToken());

	expect(postJson('/commerce/cart/discount', ['code' => 'fall15'])->getStatusCode())->toBe(403)
		->and(postJson('/commerce/cart/discount/remove', [])->getStatusCode())->toBe(403);
});

describe('in a template', function (): void {
	it('renders the form, then the applied code with a remove button', function (): void {
		($this->open)();
		$before = renderTwig('{{ cms.cart.discountForm() }}');
		applyCode('fall15');
		($this->open)();
		$after = renderTwig('{{ cms.cart.discountForm() }}');

		expect($before)->toContain('action="/commerce/cart/discount"', 'name="code"', 'name="csrf_token"', 'Apply')
			->and($before)->not->toContain('/commerce/cart/discount/remove')
			->and($after)->toContain('FALL15', 'action="/commerce/cart/discount/remove"', 'Remove')
			->and($after)->not->toContain('name="code"');
	});

	it('gives the discount and the total to a template', function (): void {
		applyCode('fall15');
		($this->open)();

		expect(renderTwig('{{ cms.cart.discount().code }}|{{ cms.cart.discount().type }}|{{ cms.cart.discount().amount }}|{{ cms.cart.discount().formatted }}|{{ cms.cart.subtotal() }}|{{ cms.cart.total() }}|{{ cms.cart.lines()[0].discountTotal }}'))
			->toBe('FALL15|percent|487|$4.87|3249|2762|300');
	});

	it('has no discount without a code', function (): void {
		($this->open)();

		expect(renderTwig('{{ cms.cart.discount() is null ? "none" : "some" }}|{{ cms.cart.total() }}'))->toBe('none|3249');
	});

	it('renders nothing with the gate closed', function (): void {
		bootCommerceStore(open: false);

		expect(renderTwig('[{{ cms.cart.discountForm() }}][{{ cms.cart.discount() is null ? "none" : "some" }}][{{ cms.cart.total() }}]'))->toBe('[][none][0]');
	});
});
