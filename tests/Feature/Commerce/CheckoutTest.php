<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Commerce\Contract\CheckoutValidator;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Service\CheckoutValidatorRegistry;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * Checkout, over HTTP. The buyer says how they pay and where it goes; the
 * server builds the order from the catalog, hands the provider the total it
 * worked out, and sends the buyer there. Coming back never marks an order
 * paid: only the provider's own word does.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->fake   = $this->fakeProvider;
	$this->orders = $this->c->get(OrderRepository::class);
	$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'sample', 'title' => 'Free Sample', 'status' => 'active', 'type' => 'digital', 'price' => 0]);
	$this->c->get(\TotalCMS\Domain\Auth\Service\ActorContext::class)->set(\TotalCMS\Domain\Auth\Data\Actor::anonymous());

	// A browser on the site's own pages passes the CSRF check by its Origin.
	// The test client sends none, so it carries the session's token instead.
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->add = function (string $productId, string $variantId = '', int $qty = 1): void {
		expect(postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => $variantId, 'qty' => $qty])->getStatusCode())->toBe(200);
	};
	$this->buy = fn (array $more = [], array $headers = []) => post('/commerce/checkout', $more + ['provider' => 'fake', 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], $headers);

	/** @return list<OrderData> every order, oldest first */
	$this->all = function (): array {
		$found = [];
		foreach ($this->c->get(IndexReader::class)->fetchIndex('orders')->objects as $row) {
			$found[] = $this->orders->find((string)$row['id']);
		}
		usort($found, static fn (OrderData $a, OrderData $b): int => $a->number() <=> $b->number());

		return $found;
	};
	$this->only   = fn (): OrderData => count(($this->all)()) === 1 ? ($this->all)()[0] : throw new RuntimeException('Expected one order, found ' . count(($this->all)()));
	$this->code   = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
	$this->link   = fn (OrderData $order, string $page): string => "/commerce/{$page}/{$order->id()}?t=" . rawurlencode($this->c->get(OrderAccess::class)->token($order));
	$this->status = fn (OrderData $order): string => "/commerce/order/{$order->id()}/status?t=" . rawurlencode($this->c->get(OrderAccess::class)->token($order));
});

describe('starting a checkout', function (): void {
	it('charges the server total and redirects to the provider', function (): void {
		($this->add)('tee', 'large', 2);
		$res   = ($this->buy)(['grandTotal' => 1, 'price' => 1, 'total' => 1]);
		$order = ($this->only)();

		expect($res->getStatusCode())->toBe(303)
			->and($res->getHeaderLine('Location'))->toBe('https://pay.example/' . $order->id())
			->and($this->fake->checkouts)->toHaveCount(1)
			->and($this->fake->checkouts[0]->total->amount())->toBe(3998)
			->and($this->fake->checkouts[0]->total->currency())->toBe('USD')
			->and($order->paymentStatus())->toBe('pending')
			->and($order->grandTotal())->toBe(3998)
			->and($order->provider())->toBe('fake')
			->and($order->providerSessionRef())->toBe('cs_fake_' . $order->id())
			->and($order->email())->toBe('a@example.com')
			->and($order->shippingAddress()['country'])->toBe('US');
	});

	it('hands the provider the whole order, and nothing from the browser', function (): void {
		($this->add)('tee', 'large', 2);
		($this->add)('mug');
		($this->buy)();
		$order   = ($this->only)();
		$request = $this->fake->checkouts[0];

		expect($request->orderId)->toBe($order->id())
			->and($request->orderNumber)->toBe(1001)
			->and($request->email)->toBe('a@example.com')
			->and($request->mode)->toBe('payment')
			->and($request->uiMode)->toBe('hosted')
			->and($request->returnUrl)->toBe('')
			->and($request->idempotencyKey)->toBe('checkout-' . $order->id())
			->and($request->metadata)->toMatchArray(['orderId' => $order->id(), 'orderNumber' => '1001'])
			->and($request->shipping->amount())->toBe(0)
			->and($request->tax->amount())->toBe(0)
			->and(array_map(fn ($l): array => [$l->title, $l->qty, $l->unitAmount->amount(), $l->discount->amount(), $l->recurrence], $request->lines))
			->toBe([['Tee <i>Shirt</i> — Large', 2, 1999, 0, null], ['Mug', 1, 1250, 0, null]])
			->and($request->successUrl)->toContain("/commerce/return/{$order->id()}?t=")
			->and($request->cancelUrl)->toContain("/commerce/cancel/{$order->id()}?t=");
	});

	it('writes down that a checkout was started', function (): void {
		($this->add)('mug');
		($this->buy)();
		$lines = $this->c->get(TransactionLedger::class)->forOrder(($this->only)()->id());

		expect($lines)->toHaveCount(1)
			->and($lines[0])->toMatchArray(['type' => 'checkout_created', 'status' => 'pending', 'amount' => 1250, 'providerRef' => 'cs_fake_' . ($this->only)()->id()]);
	});

	it('answers a script with the address to go to, and an HTMX page with a redirect', function (): void {
		($this->add)('mug');
		$json = ($this->buy)([], ['Accept' => 'application/json']);
		$htmx = ($this->buy)([], ['HX-Request' => 'true']);

		expect($json->getStatusCode())->toBe(200)
			->and(json_decode((string)$json->getBody(), true)['redirectUrl'])->toStartWith('https://pay.example/')
			->and($htmx->getStatusCode())->toBe(200)
			->and($htmx->getHeaderLine('HX-Redirect'))->toStartWith('https://pay.example/');
	});

	it('cancels the previous pending order for the same cart', function (): void {
		($this->add)('mug');
		($this->buy)();
		($this->add)('mug');
		($this->buy)();
		[$first, $second] = ($this->all)();

		expect([$first->status(), $first->paymentStatus()])->toBe(['cancelled', 'expired'])
			->and([$second->status(), $second->paymentStatus()])->toBe(['open', 'pending'])
			->and($second->grandTotal())->toBe(2500)
			->and($second->cartKey())->toBe($first->cartKey())
			->and($first->cartKey())->not->toBe('');
	});

	it('cancels the order when the provider fails', function (): void {
		($this->add)('mug');
		$this->fake->failNextCheckout();
		$res   = ($this->buy)([], ['Accept' => 'application/json']);
		$order = ($this->only)();

		expect($res->getStatusCode())->toBe(502)
			->and(($this->code)($res))->toBe('provider_error')
			->and((string)$res->getBody())->not->toContain('fake_failure', 'told to fail')
			->and([$order->status(), $order->paymentStatus()])->toBe(['cancelled', 'expired']);
	});

	it('marks a zero-total order paid without a provider call', function (): void {
		($this->add)('sample');
		$res   = ($this->buy)();
		$order = ($this->only)();

		expect($res->getStatusCode())->toBe(303)
			->and($res->getHeaderLine('Location'))->toContain("/commerce/return/{$order->id()}?t=")
			->and($this->fake->checkouts)->toBe([])
			->and($order->paymentStatus())->toBe('paid')
			->and($order->provider())->toBe('free')
			->and($order->grandTotal())->toBe(0)
			->and($order->hasFlag('paidDispatched'))->toBeTrue();
	});

	it('takes a free order even when the store has no way to pay switched on', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['providers' => []] + $config->commerce;
		($this->add)('sample');

		expect(($this->buy)(['provider' => ''])->getStatusCode())->toBe(303)->and(($this->only)()->paymentStatus())->toBe('paid');
	});

	it('refuses an unknown or disabled provider', function (array $body, Closure $arrange): void {
		($this->add)('mug');
		$arrange($this);
		$res = ($this->buy)($body, ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(503)
			->and(($this->code)($res))->toBe('checkout_unavailable')
			->and(($this->all)())->toBe([]);
	})->with([
		'unknown'      => [['provider' => 'nothing'], fn () => null],
		'none named'   => [['provider' => ''], fn () => null],
		'switched off' => [[], function ($test): void {
			$config           = $test->c->get(Config::class);
			$config->commerce = ['providers' => []] + $config->commerce;
		}],
		'no keys'      => [[], fn ($test) => $test->fake->configured = false],
		'wrong money'  => [[], fn ($test) => $test->fake->currencies = ['EUR']],
	]);

	it('refuses an empty cart', function (): void {
		$res = ($this->buy)([], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(422)->and(($this->code)($res))->toBe('cart_empty');
	});

	it('refuses an email that is not one', function (string $email): void {
		($this->add)('mug');
		$res = ($this->buy)(['email' => $email], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(422)->and(($this->code)($res))->toBe('email_invalid')->and(($this->all)())->toBe([]);
	})->with(['', 'not-an-email', 'a@b']);

	it('needs a country for an order that ships, and not for one that does not', function (): void {
		($this->add)('mug');
		$ships = ($this->buy)(['shippingAddress' => ['city' => 'Paris']], ['Accept' => 'application/json']);
		postJson('/commerce/cart/clear', []);
		($this->add)('sample');
		$digital = ($this->buy)(['shippingAddress' => []], ['Accept' => 'application/json']);

		expect($ships->getStatusCode())->toBe(422)
			->and(($this->code)($ships))->toBe('address_required')
			->and($digital->getStatusCode())->toBe(200);
	});

	it('refuses to sell more than there is', function (): void {
		$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'last-one', 'title' => 'Last One', 'status' => 'active', 'price' => 5, 'trackInventory' => true, 'stock' => 2]);
		($this->add)('last-one', '', 2);
		$product          = $this->c->get(\TotalCMS\Domain\Object\Service\ObjectFetcher::class)->fetchObject('products', 'last-one')->toArray();
		$product['stock'] = 1;
		$this->c->get(\TotalCMS\Domain\Object\Service\ObjectUpdater::class)->updateObject('products', 'last-one', $product);

		$res = ($this->buy)([], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(409)->and(($this->code)($res))->toBe('out_of_stock')->and(($this->all)())->toBe([]);
	});

	it('sends a plain form back to its page with the reason, once', function (): void {
		($this->add)('mug');
		$page = 'https://' . $this->c->get(Config::class)->domain . '/cart';
		$res  = ($this->buy)(['email' => 'nope'], ['Referer' => $page]);

		expect($res->getStatusCode())->toBe(303)
			->and($res->getHeaderLine('Location'))->toBe($page)
			->and((string)get('/commerce/cart?part=page', ['HX-Request' => 'true'])->getBody())->toContain('Enter an email address for your receipt.')
			->and((string)get('/commerce/cart?part=page', ['HX-Request' => 'true'])->getBody())->not->toContain('Enter an email address');
	});

	it('writes the reason into the form for an HTMX page', function (): void {
		($this->add)('mug');
		$res = ($this->buy)(['email' => 'nope'], ['HX-Request' => 'true']);

		expect($res->getStatusCode())->toBe(200)
			->and($res->getHeaderLine('X-Commerce-Error'))->toBe('email_invalid')
			->and($res->hasHeader('HX-Redirect'))->toBeFalse()
			->and((string)$res->getBody())->toContain('role="alert"', 'Enter an email address for your receipt.');
	});

	it('dispatches order.created once', function (): void {
		$heard = [];
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::ORDER_CREATED, function (array $payload) use (&$heard): void {
			$heard[] = $payload;
		});
		($this->add)('mug');
		($this->buy)();

		expect($heard)->toHaveCount(1)
			->and($heard[0])->toMatchArray(['orderId' => ($this->only)()->id(), 'paymentStatus' => 'pending', 'grandTotal' => 1250, 'amount' => 1250]);
	});

	it('keeps the buyer\'s cart for the order, and takes it out of their active carts', function (): void {
		($this->add)('mug');
		($this->buy)();
		$order = ($this->only)();
		$cart  = $this->c->get(\TotalCMS\Domain\Object\Service\ObjectFetcher::class)->fetchObjectFromDisk('carts', $order->cartKey())->toArray();

		expect($cart['status'])->toBe('checkout')
			->and($cart['orderId'])->toBe($order->id())
			->and($cart['items'][0])->toMatchArray(['productId' => 'mug', 'qty' => 1]);
	});

	it('is closed when the store is', function (): void {
		bootCommerceStore(open: false);
		withHeader('X-CSRF-Token', $this->app->getContainer()->get(CSRFTokenManager::class)->getToken());

		expect(post('/commerce/checkout', ['provider' => 'fake', 'email' => 'a@example.com'])->getStatusCode())->toBe(403);
	});
});

describe('checkout validators', function (): void {
	beforeEach(function (): void {
		($this->add)('mug');
		$this->validator = fn (Closure $validate): CheckoutValidator => new class($validate) implements CheckoutValidator {
			public function __construct(private readonly Closure $validate)
			{
			}

			public function validate(OrderDraft $draft, PricingContext $context): ?string
			{
				return ($this->validate)($draft, $context);
			}
		};
	});

	it('a checkout validator refusal stops the checkout before the provider', function (): void {
		$this->c->get(CheckoutValidatorRegistry::class)->add(($this->validator)(fn () => null));
		$this->c->get(CheckoutValidatorRegistry::class)->add(($this->validator)(fn () => 'Bad license'));
		$res = ($this->buy)([], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(422)
			->and(($this->code)($res))->toBe('checkout_invalid')
			->and(json_decode((string)$res->getBody(), true)['error']['message'])->toBe('Bad license')
			->and($this->fake->checkouts)->toBe([])
			->and(($this->all)())->toBe([]);
	});

	it('a checkout validator that throws refuses the checkout', function (): void {
		$this->c->get(CheckoutValidatorRegistry::class)->add(($this->validator)(fn () => throw new RuntimeException('license server down')));
		$res = ($this->buy)([], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(422)
			->and(($this->code)($res))->toBe('checkout_invalid')
			->and((string)$res->getBody())->not->toContain('license server down')
			->and($this->fake->checkouts)->toBe([])
			->and(($this->all)())->toBe([]);
	});

	it('lets the checkout through when every validator agrees', function (): void {
		$seen = null;
		$this->c->get(CheckoutValidatorRegistry::class)->add(($this->validator)(function (OrderDraft $draft) use (&$seen): ?string {
			$seen = $draft->totals->grandTotal;

			return null;
		}));

		expect(($this->buy)()->getStatusCode())->toBe(303)->and($seen)->toBe(1250);
	});
});

describe('coming back from the provider', function (): void {
	beforeEach(function (): void {
		($this->add)('mug');
		($this->buy)();
		$this->order = ($this->only)();
	});

	it('return page never marks an order paid', function (): void {
		$res = get(($this->link)($this->order, 'return'));

		expect($res->getStatusCode())->toBe(200)
			->and((string)$res->getBody())->toContain('Confirming your payment', 'Order 1001', 'hx-get')
			->and($this->orders->find($this->order->id())->paymentStatus())->toBe('pending')
			->and($this->fake->returns)->toBe([$this->order->id()]);
	});

	it('return page requires a valid token', function (string $query): void {
		expect(get("/commerce/return/{$this->order->id()}{$query}")->getStatusCode())->toBe(404);
	})->with(['', '?t=', '?t=forged', '?t[]=x']);

	it('does not open with a link to another order', function (): void {
		$other = $this->orders->create(commerceDraft(), 'fake');
		$token = rawurlencode($this->c->get(OrderAccess::class)->token($other));

		expect(get("/commerce/return/{$this->order->id()}?t={$token}")->getStatusCode())->toBe(404);
	});

	it('empties the cart the order came from, once', function (): void {
		get(($this->link)($this->order, 'return'));
		$afterReturn = $this->c->get(PhpSession::class)->get(SessionKeys::COMMERCE_CART)['items'] ?? [];
		($this->add)('tee', 'large');
		get(($this->link)($this->order, 'return'));
		$afterSecond = $this->c->get(PhpSession::class)->get(SessionKeys::COMMERCE_CART)['items'] ?? [];

		expect($afterReturn)->toBe([])->and($afterSecond)->toHaveCount(1);
	});

	it('status poll shows paid after the webhook', function (): void {
		$before = (string)get(($this->status)($this->order))->getBody();
		$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($this->order->id(), 1250));
		$after = get(($this->status)($this->order));

		expect($before)->toContain('Confirming your payment', 'hx-get')
			->and((string)$after->getBody())->toContain('Payment received')
			->and((string)$after->getBody())->not->toContain('hx-get')
			->and((string)get(($this->link)($this->order, 'return'))->getBody())->toContain('Payment received');
	});

	it('the status poll needs the order\'s link too', function (): void {
		expect(get("/commerce/order/{$this->order->id()}/status")->getStatusCode())->toBe(404);
	});

	it('cancel brings the buyer back to the page they checked out from', function (): void {
		$page = 'https://' . $this->c->get(Config::class)->domain . '/cart';
		($this->buy)([], ['Referer' => $page]);
		$latest = ($this->all)()[1];

		expect(get(($this->link)($latest, 'cancel'))->getHeaderLine('Location'))->toBe($page);
	});

	it('cancel sends the buyer back to their cart and leaves the order pending', function (): void {
		$res = get(($this->link)($this->order, 'cancel'));

		expect($res->getStatusCode())->toBe(303)
			->and($res->getHeaderLine('Location'))->toBe('/')
			->and($this->orders->find($this->order->id())->paymentStatus())->toBe('pending')
			->and($this->c->get(PhpSession::class)->get(SessionKeys::COMMERCE_CART)['items'] ?? [])->toHaveCount(1);
	});

	it('says what went wrong when the order was not paid', function (string $status, string $says): void {
		$this->orders->withLock($this->order->id(), fn (OrderData $o): OrderData => $o->with(['paymentStatus' => $status]));

		expect((string)get(($this->status)($this->order))->getBody())->toContain($says);
	})->with([['failed', 'did not go through'], ['expired', 'was not completed'], ['review', 'being checked'], ['refunded', 'refunded']]);
});

describe('in a template', function (): void {
	// A page render has the visitor's session open; the test client closes it after each request.
	beforeEach(function (): void {
		$this->open = function (): void {
			$session = $this->c->get(PhpSession::class);
			$session->isStarted() || $session->start();
		};
	});

	it('cms.cart.checkoutForm() offers the ways to pay and asks where to send it', function (): void {
		($this->add)('mug');
		($this->open)();
		$html = renderTwig('{{ cms.cart.checkoutForm() }}');

		expect($html)->toContain('action="/commerce/checkout"', 'name="email"', 'name="provider"', 'value="fake"', 'Fake Pay', 'name="shippingAddress[country]"', 'name="csrf_token"');
	});

	// A browser or a password manager fills an address field only when the
	// field says which part of the address it is.
	it('tells the browser what each address field is, so it can be filled in', function (string $field, string $token): void {
		($this->add)('mug');
		($this->open)();
		$html = renderTwig('{{ cms.cart.checkoutForm() }}');

		preg_match('/<input[^>]*name="shippingAddress\[' . $field . '\]"[^>]*>/', $html, $input);

		expect($input[0] ?? '')->toContain('autocomplete="' . $token . '"');
	})->with([
		['name', 'shipping name'],
		['line1', 'shipping address-line1'],
		['line2', 'shipping address-line2'],
		['city', 'shipping address-level2'],
		['region', 'shipping address-level1'],
		['postalCode', 'shipping postal-code'],
		['country', 'shipping country'],
	]);

	it('asks for no address when nothing ships', function (): void {
		($this->add)('sample');
		($this->open)();

		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toContain('name="email"')->not->toContain('shippingAddress');
	});

	it('is nothing for an empty cart, or a store that is closed', function (): void {
		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toBe('');
	});

	it('cms.commerce.order() gives the order to someone holding its link, and nothing its secrets', function (): void {
		($this->add)('mug');
		($this->buy)();
		$order = ($this->only)();
		$token = $this->c->get(OrderAccess::class)->token($order);

		$out = renderTwig('{% set o = cms.commerce.order(id, token) %}{{ o.number }}|{{ o.grandTotal }}|{{ o.paymentStatus }}|{{ o.accessToken ?? "none" }}|{{ o.providerClientSecret ?? "none" }}|{{ cms.commerce.order(id, "forged") is null ? "null" : "found" }}', ['id' => $order->id(), 'token' => $token]);

		expect($out)->toBe('1001|1250|pending|none|none|null');
	});
});
