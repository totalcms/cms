<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * The storefront's cart, over HTTP. One set of routes answers a script with
 * JSON, an HTMX page with a partial, and a plain form with a redirect. None
 * of them reads a price from the request.
 */

/** Boot a site with a few products; $open switches the store on. */
function bootCartSite(bool $open = true): void
{
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	restoreFixtures();
	$app = bootstrap();
	test()->setUpApp($app);
	$c = $app->getContainer();
	if ($open) {
		enableCommerce($app);
	}

	$c->get(CommerceInstaller::class)->install();
	$save = fn (array $product) => $c->get(ObjectSaver::class)->saveObject('products', $product);
	$save(['id' => 'tee', 'title' => 'Tee <b>Shirt</b>', 'status' => 'active', 'price' => 15, 'variants' => ['large' => ['id' => 'large', 'title' => 'Large', 'price' => 19.99]]]);
	$save(['id' => 'mug', 'title' => 'Mug', 'status' => 'active', 'price' => 12.5]);
	$save(['id' => 'print', 'title' => 'Print', 'status' => 'active', 'price' => 40, 'trackInventory' => true, 'stock' => 1]);

	// A browser on the site's own pages passes the CSRF check by its Origin.
	// The test client sends none, so it carries the session's token instead.
	$session = $c->get(PhpSession::class);
	$session->isStarted() || $session->start();
	withHeader('X-CSRF-Token', $c->get(CSRFTokenManager::class)->getToken());
}

beforeEach(fn () => bootCartSite());

function cartJson($response): array
{
	return json_decode((string)$response->getBody(), true) ?? [];
}

const HTMX = ['HX-Request' => 'true'];

describe('for a script', function (): void {
	test('adding a product answers with the cart', function (): void {
		$response = postJson('/commerce/cart/add', ['productId' => 'tee', 'variantId' => 'large', 'qty' => 1]);
		$cart     = cartJson($response);

		expect($response->getStatusCode())->toBe(200)
			->and($cart['count'])->toBe(1)
			->and($cart['subtotal'])->toBe(1999)
			->and($cart['currency'])->toBe('USD')
			->and($cart['notices'])->toBe([])
			->and($cart['lines'])->toHaveCount(1)
			->and($cart['lines'][0])->toMatchArray(['productId' => 'tee', 'variantId' => 'large', 'title' => 'Tee <b>Shirt</b>', 'variantTitle' => 'Large', 'qty' => 1, 'unitPrice' => 1999, 'lineTotal' => 1999, 'priceSource' => 'catalog'])
			->and($cart['lines'][0]['lineKey'])->toMatch('/^[0-9a-f]{12}$/');
	});

	test('a price in the request changes nothing', function (): void {
		$honest = cartJson(postJson('/commerce/cart/add', ['productId' => 'mug', 'qty' => 2]));
		bootCartSite();
		$forged = cartJson(postJson('/commerce/cart/add', ['productId' => 'mug', 'qty' => 2, 'price' => 1, 'unitPrice' => 1, 'amount' => 1, 'total' => 1]));

		expect($forged)->toBe($honest)
			->and($forged['subtotal'])->toBe(2500)
			->and(json_encode($this->app->getContainer()->get(PhpSession::class)->get(SessionKeys::COMMERCE_CART)))->not->toContain('price');
	});

	test('quantity is 1 when none is sent, and must be a number when one is', function (): void {
		expect(cartJson(postJson('/commerce/cart/add', ['productId' => 'mug']))['count'])->toBe(1);

		$response = postJson('/commerce/cart/add', ['productId' => 'mug', 'qty' => 'lots']);
		expect($response->getStatusCode())->toBe(422)
			->and(cartJson($response)['error']['code'])->toBe('qty_invalid');
	});

	test('a refusal carries its code, a message and its status', function (array $body, int $status, string $code): void {
		$response = postJson('/commerce/cart/add', $body);

		expect($response->getStatusCode())->toBe($status)
			->and(cartJson($response)['error']['code'])->toBe($code)
			->and(cartJson($response)['error']['message'])->not->toBe('');
	})->with([
		'unknown product'  => [['productId' => 'nothing'], 404, 'product_unavailable'],
		'no product'       => [[], 404, 'product_unavailable'],
		'variant missing'  => [['productId' => 'tee'], 422, 'variant_required'],
		'variant unknown'  => [['productId' => 'tee', 'variantId' => 'huge'], 422, 'variant_invalid'],
		'too many'         => [['productId' => 'mug', 'qty' => 1000], 422, 'qty_invalid'],
		'out of stock'     => [['productId' => 'print', 'qty' => 2], 409, 'out_of_stock'],
		'array for an id'  => [['productId' => ['mug']], 404, 'product_unavailable'],
	]);

	test('GET /commerce/cart lists the cart', function (): void {
		postJson('/commerce/cart/add', ['productId' => 'tee', 'variantId' => 'large', 'qty' => 2]);

		$response = get('/commerce/cart', ['Accept' => 'application/json']);

		expect($response->getStatusCode())->toBe(200)
			->and(cartJson($response)['lines'][0]['unitPrice'])->toBe(1999)
			->and(cartJson($response)['lines'][0]['lineTotal'])->toBe(3998)
			->and(cartJson($response)['count'])->toBe(2);
	});

	test('update, remove and clear change the cart', function (): void {
		$line = cartJson(postJson('/commerce/cart/add', ['productId' => 'mug', 'qty' => 2]))['lines'][0]['lineKey'];
		postJson('/commerce/cart/add', ['productId' => 'tee', 'variantId' => 'large']);

		expect(cartJson(postJson('/commerce/cart/update', ['lineKey' => $line, 'qty' => 5]))['count'])->toBe(6)
			->and(cartJson(postJson('/commerce/cart/update', ['lineKey' => $line, 'qty' => 0]))['count'])->toBe(1)
			->and(cartJson(postJson('/commerce/cart/add', ['productId' => 'mug']))['count'])->toBe(2)
			->and(cartJson(postJson('/commerce/cart/remove', ['lineKey' => $line]))['count'])->toBe(1)
			->and(cartJson(postJson('/commerce/cart/clear', []))['lines'])->toBe([]);
	});

	test('an attribute the settings allow is kept, and any other is refused', function (): void {
		$config           = $this->app->getContainer()->get(Config::class);
		$config->commerce = ['checkoutAttributes' => ['giftNote']] + $config->commerce;

		expect(postJson('/commerce/cart/attributes', ['key' => 'giftNote', 'value' => 'Happy birthday'])->getStatusCode())->toBe(200)
			->and($this->app->getContainer()->get(PhpSession::class)->get(SessionKeys::COMMERCE_CART)['attributes'])->toBe(['giftNote' => 'Happy birthday']);

		$refused = postJson('/commerce/cart/attributes', ['key' => 'price', 'value' => '1']);
		expect($refused->getStatusCode())->toBe(422)
			->and(cartJson($refused)['error']['code'])->toBe('attribute_invalid');
	});
});

describe('for an HTMX page', function (): void {
	test('adding answers with the cart widget and tells the page the cart changed', function (): void {
		$response = post('/commerce/cart/add', ['productId' => 'tee', 'variantId' => 'large', 'qty' => 2], HTMX);
		$html     = (string)$response->getBody();

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('HX-Trigger'))->toBe('cart-updated')
			->and($response->getHeaderLine('Content-Type'))->toContain('text/html')
			->and($html)->toContain('id="cart-widget"', 'Large', '$39.98')
			// A title is whatever someone typed.
			->and($html)->toContain('Tee &lt;b&gt;Shirt&lt;/b&gt;')
			->and($html)->not->toContain('<b>Shirt</b>');
	});

	test('the widget reloads itself when the cart changes, and reloading it does not announce a change', function (): void {
		$response = get('/commerce/cart?part=widget', HTMX);
		$html     = (string)$response->getBody();

		expect($html)->toContain('id="cart-widget"', 'hx-get="/commerce/cart?part=widget"', 'hx-trigger="cart-updated from:body"', 'hx-swap="outerHTML"', 'Your cart is empty')
			// Or the widget would reload itself for ever.
			->and($response->hasHeader('HX-Trigger'))->toBeFalse();
	});

	test('the cart page is its own part, with a form for each line', function (): void {
		$line = cartJson(postJson('/commerce/cart/add', ['productId' => 'mug', 'qty' => 2]))['lines'][0]['lineKey'];

		$html = (string)get('/commerce/cart?part=page', HTMX)->getBody();

		expect($html)->toContain('id="cart-page"', 'hx-get="/commerce/cart?part=page"', 'hx-post="/commerce/cart/update?part=page"', 'hx-post="/commerce/cart/remove?part=page"', 'name="lineKey" value="' . $line . '"', 'name="csrf_token"', '$25.00');
	});

	test('a change made from the cart page answers with the cart page', function (): void {
		$line = cartJson(postJson('/commerce/cart/add', ['productId' => 'mug', 'qty' => 2]))['lines'][0]['lineKey'];

		$html = (string)post('/commerce/cart/update?part=page', ['lineKey' => $line, 'qty' => 3], HTMX)->getBody();

		expect($html)->toContain('id="cart-page"', '$37.50')
			->and($html)->not->toContain('id="cart-widget"');
	});

	test('a part it does not know is refused', function (): void {
		$response = get('/commerce/cart?part=everything', HTMX);

		expect($response->getStatusCode())->toBe(422)
			->and(cartJson($response)['error']['code'])->toBe('part_invalid');
	});

	test('a refusal answers with the widget and says why', function (): void {
		$response = post('/commerce/cart/add', ['productId' => 'print', 'qty' => 5], HTMX);
		$html     = (string)$response->getBody();

		expect($html)->toContain('id="cart-widget"', 'Only 1 left in stock.')
			->and($response->getHeaderLine('X-Commerce-Error'))->toBe('out_of_stock')
			// Nothing changed, so nothing is announced.
			->and($response->hasHeader('HX-Trigger'))->toBeFalse();
	});
});

describe('for a plain form', function (): void {
	beforeEach(function (): void {
		// A page of this site: the site's own domain, as its settings give it.
		$this->page = 'https://' . $this->app->getContainer()->get(Config::class)->domain . '/shop/mug?color=blue';
	});

	test('goes back to the page the form was on', function (): void {
		$response = post('/commerce/cart/add', ['productId' => 'mug'], ['Referer' => $this->page]);

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toBe($this->page)
			->and(cartJson(get('/commerce/cart', ['Accept' => 'application/json']))['count'])->toBe(1);
	});

	test('goes to the home page when it cannot tell where the form was', function (): void {
		$response = post('/commerce/cart/add', ['productId' => 'mug']);

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toBe('/');
	});

	test('is refused outright when it was posted from another site', function (string $referer): void {
		expect(post('/commerce/cart/add', ['productId' => 'mug'], ['Referer' => $referer])->getStatusCode())->toBe(403);
	})->with(['https://evil.example/phish', 'http://localhost.evil.example/x', '//evil.example', 'javascript:alert(1)']);

	test('never sends the buyer to another site, even when the request itself is from this one', function (): void {
		$origin   = 'https://' . $this->app->getContainer()->get(Config::class)->domain;
		$response = post('/commerce/cart/add', ['productId' => 'mug'], ['Origin' => $origin, 'Referer' => 'https://evil.example/phish']);

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toBe('/');
	});

	test('a refusal goes back too, and the cart says why once', function (): void {
		$response = post('/commerce/cart/add', ['productId' => 'print', 'qty' => 5], ['Referer' => $this->page]);

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toBe($this->page)
			->and((string)get('/commerce/cart?part=widget', HTMX)->getBody())->toContain('Only 1 left in stock.')
			->and((string)get('/commerce/cart?part=widget', HTMX)->getBody())->not->toContain('Only 1 left in stock.');
	});
});

describe('who may use it', function (): void {
	test('nobody, when the store is not switched on', function (string $method, string $path): void {
		bootCartSite(open: false);

		$response = $method === 'GET' ? get($path, ['Accept' => 'application/json']) : postJson($path, ['productId' => 'mug']);

		expect($response->getStatusCode())->toBe(403);
	})->with([['GET', '/commerce/cart'], ['POST', '/commerce/cart/add'], ['POST', '/commerce/cart/clear']]);

	test('not a page on another site', function (): void {
		$response = postJson('/commerce/cart/add', ['productId' => 'mug'], ['Origin' => 'https://evil.example']);

		expect($response->getStatusCode())->toBe(403);
	});

	test('not a caller with no origin and no token', function (): void {
		withHeader('X-CSRF-Token', 'not-the-token');

		expect(postJson('/commerce/cart/add', ['productId' => 'mug'])->getStatusCode())->toBe(403);
	});

	test('not more than the limit allows', function (): void {
		$config             = $this->app->getContainer()->get(Config::class);
		$config->rateLimits = ['commerce-cart' => ['max' => 2, 'window' => 60]] + $config->rateLimits;

		expect(postJson('/commerce/cart/add', ['productId' => 'mug'])->getStatusCode())->toBe(200)
			->and(postJson('/commerce/cart/add', ['productId' => 'mug'])->getStatusCode())->toBe(200)
			->and(postJson('/commerce/cart/add', ['productId' => 'mug'])->getStatusCode())->toBe(429);
	});
});
