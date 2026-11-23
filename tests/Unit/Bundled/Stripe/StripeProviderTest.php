<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\Stripe\Extension;
use TotalCMS\Bundled\Stripe\StripeClient;
use TotalCMS\Bundled\Stripe\StripeProvider;
use TotalCMS\Bundled\Stripe\StripeWebhookVerifier;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckoutProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/stripe/Extension.php';

/*
 * The Stripe provider, offline. Every request it would send is recorded, so
 * the tests can hold what goes to Stripe: the total the store worked out,
 * the order it is for, and never a key in an error. The webhook payloads are
 * the shapes Stripe sends, trimmed.
 */

const STRIPE_KEY    = 'sk_test_51AbCdEfGhIjKlMnOpQrStUvWxYz';
const STRIPE_SECRET = 'whsec_test_secret';
const STRIPE_PUBLIC = 'pk_test_51AbCdEfGhIjKlMnOpQrStUvWxYz';
const ORDER_ID      = '0123456789abcdef0123456789abcdef';

/** A provider that sends through $http, with test keys unless told otherwise. */
function stripeProvider(RecordingHttpClient $http, string $key = STRIPE_KEY, string $secret = STRIPE_SECRET, ?RecordingLogger $logger = null, string $descriptor = ''): StripeProvider
{
	return new StripeProvider(new StripeClient(static fn (): HttpClientInterface => $http, $key), $key, $secret, $descriptor, $logger ?? new NullLogger());
}

/** @param list<CheckoutLineSummary>|null $lines */
function stripeCheckout(?array $lines = null, int $total = 3998, array $with = []): CheckoutRequest
{
	$usd = static fn (int $amount): Money => Money::of($amount, 'USD');

	return new CheckoutRequest(...$with + [
		'orderId'        => ORDER_ID,
		'orderNumber'    => 1001,
		'total'          => $usd($total),
		'lines'          => $lines ?? [new CheckoutLineSummary('Tee', 2, $usd(1999), $usd(0), $usd(0))],
		'shipping'       => $usd(0),
		'tax'            => $usd(0),
		'email'          => 'buyer@example.com',
		'successUrl'     => 'https://shop.example/commerce/return/' . ORDER_ID . '?t=abc',
		'cancelUrl'      => 'https://shop.example/commerce/cancel/' . ORDER_ID . '?t=abc',
		'returnUrl'      => '',
		'metadata'       => ['orderId' => ORDER_ID, 'orderNumber' => '1001'],
		'idempotencyKey' => 'checkout-' . ORDER_ID,
	]);
}

/** A provider for a store that takes payment on its own page. */
function stripeOnPage(RecordingHttpClient $http, string $publishable = STRIPE_PUBLIC): StripeProvider
{
	return new StripeProvider(
		new StripeClient(static fn (): HttpClientInterface => $http, STRIPE_KEY),
		STRIPE_KEY,
		STRIPE_SECRET,
		'',
		new NullLogger(),
		$publishable,
		static fn (): string => '/api/ext/totalcms/stripe/assets/embed.js',
	);
}

function stripeSession(): HttpResponse
{
	return new HttpResponse(200, (string)json_encode(['id' => 'cs_test_a1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1']));
}

/** The form a recorded request carried, as PHP reads it. */
function stripeForm(RecordingHttpClient $http, int $index = 0): array
{
	parse_str((string)($http->requests[$index]['options']['body'] ?? ''), $form);

	return $form;
}

/** @return list<string> */
function stripeHeaders(RecordingHttpClient $http, int $index = 0): array
{
	return array_values($http->requests[$index]['options']['headers'] ?? []);
}

function stripeWebhook(string $fixture): ServerRequestInterface
{
	$factory = new Psr17Factory();
	$body    = (string)file_get_contents(dirname(__DIR__, 3) . "/fixtures/stripe/{$fixture}.json");

	return $factory->createServerRequest('POST', 'https://shop.example/commerce/webhook/stripe')->withBody($factory->createStream($body));
}

describe('starting a checkout', function (): void {
	it('asks Stripe for a hosted page for exactly the total the store worked out', function (): void {
		$http    = new RecordingHttpClient(stripeSession());
		$session = stripeProvider($http)->createCheckout(stripeCheckout());
		$form    = stripeForm($http);

		expect($http->requests)->toHaveCount(1)
			->and($http->requests[0]['method'])->toBe('POST')
			->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions')
			->and($session->providerSessionRef)->toBe('cs_test_a1')
			->and($session->redirectUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_a1')
			->and($form)->toMatchArray([
				'mode'                => 'payment',
				'ui_mode'             => 'hosted_page',
				'client_reference_id' => ORDER_ID,
				'customer_email'      => 'buyer@example.com',
				'success_url'         => 'https://shop.example/commerce/return/' . ORDER_ID . '?t=abc',
				'cancel_url'          => 'https://shop.example/commerce/cancel/' . ORDER_ID . '?t=abc',
				'metadata'            => ['orderId' => ORDER_ID, 'orderNumber' => '1001'],
			])
			->and($form['payment_intent_data'])->toBe(['metadata' => ['orderId' => ORDER_ID, 'orderNumber' => '1001'], 'transfer_group' => 'order-1001'])
			->and($form['line_items'])->toBe([[
				'quantity'   => '2',
				'price_data' => ['currency' => 'usd', 'unit_amount' => '1999', 'product_data' => ['name' => 'Tee']],
			]]);
	});

	it('sends the key, the pinned API version and an idempotency key as headers', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http)->createCheckout(stripeCheckout());

		expect(stripeHeaders($http))->toContain(
			'Authorization: Bearer ' . STRIPE_KEY,
			'Stripe-Version: 2026-11-18.endive',
			'Idempotency-Key: checkout-' . ORDER_ID,
			'Content-Type: application/x-www-form-urlencoded',
		);
	});

	it('puts a discounted line on the page as one item at what is charged for it', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		$line = new CheckoutLineSummary('Tee', 2, Money::of(1999, 'USD'), Money::of(600, 'USD'), Money::of(0, 'USD'));
		stripeProvider($http)->createCheckout(stripeCheckout([$line], 3398));

		expect(stripeForm($http)['line_items'])->toBe([[
			'quantity'   => '1',
			'price_data' => ['currency' => 'usd', 'unit_amount' => '3398', 'product_data' => ['name' => '2 × Tee']],
		]]);
	});

	it('adds shipping and tax as items of their own, so Stripe\'s total is the store\'s', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http)->createCheckout(stripeCheckout(null, 3998 + 500 + 320, ['shipping' => Money::of(500, 'USD'), 'tax' => Money::of(320, 'USD')]));
		$items = stripeForm($http)['line_items'];

		expect(array_map(static fn (array $i): array => [$i['price_data']['product_data']['name'], $i['quantity'], $i['price_data']['unit_amount']], $items))
			->toBe([['Tee', '2', '1999'], ['Shipping', '1', '500'], ['Tax', '1', '320']]);
	});

	it('refuses to call Stripe when the items do not add up to the total', function (): void {
		$http = new RecordingHttpClient(stripeSession());

		expect(fn () => stripeProvider($http)->createCheckout(stripeCheckout(null, 4000)))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('total_mismatch'))
			->and($http->requests)->toBe([]);
	});

	it('refuses a kind of checkout it cannot do yet, without calling Stripe', function (array $with): void {
		$http = new RecordingHttpClient(stripeSession());

		expect(fn () => stripeProvider($http)->createCheckout(stripeCheckout(null, 3998, $with)))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('unsupported'))
			->and($http->requests)->toBe([]);
	})->with([
		'a subscription'       => [['mode' => 'subscription']],
		'a form of its own'    => [['uiMode' => 'elements']],
	]);

	it('cuts metadata to what Stripe takes, and says so in the log', function (): void {
		$http     = new RecordingHttpClient(stripeSession());
		$logger   = new RecordingLogger();
		$metadata = ['orderId' => ORDER_ID, 'orderNumber' => '1001', 'note' => str_repeat('x', 600)];
		for ($i = 0; $i < 60; $i++) {
			$metadata["k{$i}"] = 'v';
		}
		stripeProvider($http, logger: $logger)->createCheckout(stripeCheckout(null, 3998, ['metadata' => $metadata]));
		$sent = stripeForm($http)['metadata'];

		expect(count($sent))->toBe(50)
			->and($sent['orderId'])->toBe(ORDER_ID)
			->and(strlen($sent['note']))->toBe(500)
			->and($logger->records)->toHaveCount(1)
			->and($logger->records[0]['level'])->toBe('warning');
	});

	it('adds the statement descriptor suffix when the store set one', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http, descriptor: 'TEE SHOP')->createCheckout(stripeCheckout());

		expect(stripeForm($http)['payment_intent_data']['statement_descriptor_suffix'])->toBe('TEE SHOP');
	});
});

describe('taking payment on the store\'s own page', function (): void {
	beforeEach(function (): void {
		$this->http    = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'cs_test_e1', 'client_secret' => 'cs_test_e1_secret_xyz'])));
		$this->request = stripeCheckout(with: ['uiMode' => 'embedded', 'returnUrl' => 'https://shop.example/commerce/return/' . ORDER_ID . '?t=abc']);
	});

	it('asks Stripe for an embedded page that comes back to the store', function (): void {
		stripeOnPage($this->http)->createCheckout($this->request);
		$form = stripeForm($this->http);

		expect($form['ui_mode'])->toBe('embedded_page')
			->and($form['return_url'])->toBe('https://shop.example/commerce/return/' . ORDER_ID . '?t=abc')
			->and($form)->not->toHaveKeys(['success_url', 'cancel_url'])
			->and($form['mode'])->toBe('payment')
			->and($form['client_reference_id'])->toBe(ORDER_ID)
			->and($form['line_items'][0]['price_data']['unit_amount'])->toBe('1999');
	});

	it('hands back the client secret and no page to go to', function (): void {
		$session = stripeOnPage($this->http)->createCheckout($this->request);

		expect($session->providerSessionRef)->toBe('cs_test_e1')
			->and($session->redirectUrl)->toBe('')
			->and($session->clientSecret)->toBe('cs_test_e1_secret_xyz');
	});

	it('refuses an answer with no client secret', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'cs_test_e1'])));

		expect(fn () => stripeOnPage($http)->createCheckout($this->request))->toThrow(ProviderException::class, 'Stripe answered without a checkout to mount.');
	});

	it('still checks the total before asking', function (): void {
		$request = stripeCheckout(total: 4000, with: ['uiMode' => 'embedded', 'returnUrl' => 'https://shop.example/r']);

		expect(fn () => stripeOnPage($this->http)->createCheckout($request))->toThrow(ProviderException::class)
			->and($this->http->requests)->toBe([]);
	});

	it('gives the page the publishable key and the client secret, and never a secret of the store\'s', function (): void {
		$provider = stripeOnPage($this->http);
		$embed    = $provider->embed($provider->createCheckout($this->request));

		expect($embed->moduleUrl)->toBe('/api/ext/totalcms/stripe/assets/embed.js')
			->and($embed->config)->toBe(['publishableKey' => STRIPE_PUBLIC, 'clientSecret' => 'cs_test_e1_secret_xyz', 'scriptUrl' => 'https://js.stripe.com/endive/stripe.js'])
			->and(json_encode($embed))->not->toContain(STRIPE_KEY)
			->and(json_encode($embed))->not->toContain(STRIPE_SECRET);
	});

	it('still asks for a hosted page when the checkout is a hosted one', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeOnPage($http)->createCheckout(stripeCheckout());

		expect(stripeForm($http))->toMatchArray(['ui_mode' => 'hosted_page', 'success_url' => 'https://shop.example/commerce/return/' . ORDER_ID . '?t=abc'])
			->and(stripeForm($http))->not->toHaveKey('return_url');
	});

	it('can show its form on the store\'s page only with a publishable key, and is ready to take payment either way', function (string $publishable, bool $canEmbed): void {
		$provider = stripeOnPage(new RecordingHttpClient(), $publishable);

		expect($provider->capabilities()->embedded)->toBe($canEmbed)
			->and($provider->isConfigured())->toBeTrue();
	})->with([
		'a test key'   => [STRIPE_PUBLIC, true],
		'a live key'   => ['pk_live_51AbCdEfGhIjKlMnOp', true],
		'no key'       => ['', false],
		'a secret key' => [STRIPE_KEY, false],
	]);
});

describe('when Stripe says no', function (): void {
	it('turns its error into one that names the type and code, and never the key', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(402, (string)json_encode(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined. Key ' . STRIPE_KEY]])));

		expect(fn () => stripeProvider($http)->createCheckout(stripeCheckout()))
			->toThrow(function (ProviderException $e): void {
				expect($e->providerCode)->toBe('card_declined')
					->and($e->getMessage())->toContain('402', 'card_error', 'card_declined')
					->and($e->getMessage())->not->toContain(STRIPE_KEY, 'sk_test');
			});
	});

	it('uses the error type when there is no code', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(400, (string)json_encode(['error' => ['type' => 'invalid_request_error']])));

		expect(fn () => stripeProvider($http)->createCheckout(stripeCheckout()))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('invalid_request_error'));
	});

	it('says so when Stripe cannot be reached, or answers with something that is not JSON', function (HttpResponse ...$responses): void {
		$http = new RecordingHttpClient(...$responses);

		expect(fn () => stripeProvider($http)->createCheckout(stripeCheckout()))->toThrow(ProviderException::class);
	})->with([
		'no answer'    => [],
		'a proxy page' => [new HttpResponse(502, '<html>Bad gateway</html>')],
		'empty 200'    => [new HttpResponse(200, '')],
		'no session'   => [new HttpResponse(200, '{"object":"checkout.session"}')],
	]);
});

describe('reading a webhook', function (): void {
	it('reads each event the store uses', function (string $fixture, PaymentEventType $type, string $orderId, int $amount, string $ref, string $paymentRef): void {
		$event = stripeProvider(new RecordingHttpClient())->parseWebhook(stripeWebhook($fixture));

		expect($event)->toBeInstanceOf(PaymentEvent::class)
			->and($event->provider)->toBe('stripe')
			->and($event->type)->toBe($type)
			->and($event->orderId)->toBe($orderId)
			->and($event->amount->amount())->toBe($amount)
			->and($event->amount->currency())->toBe('USD')
			->and($event->providerRef)->toBe($ref)
			->and($event->providerPaymentRef)->toBe($paymentRef)
			->and($event->eventKey())->toStartWith('stripe:evt_1');
	})->with([
		'paid'                       => ['checkout.session.completed', PaymentEventType::Paid, ORDER_ID, 3998, 'cs_test_a1', 'pi_3A1'],
		'paid later (bank payment)'  => ['checkout.session.async_payment_succeeded', PaymentEventType::Paid, ORDER_ID, 3998, 'cs_test_a2', 'pi_3A2'],
		'failed later'               => ['checkout.session.async_payment_failed', PaymentEventType::Failed, ORDER_ID, 3998, 'cs_test_a3', 'pi_3A3'],
		'page expired'               => ['checkout.session.expired', PaymentEventType::Expired, ORDER_ID, 3998, 'cs_test_a4', ''],
		// The newest refund, not the running total: 1000 of the 1500 refunded so far.
		'refunded'                   => ['charge.refunded', PaymentEventType::Refunded, ORDER_ID, 1000, 're_3A1b', 'pi_3A1'],
		// No list of refunds on the charge: the difference from what was refunded before.
		'refunded, no list'          => ['charge.refunded.no-list', PaymentEventType::Refunded, '', 1000, 'ch_3A1', 'pi_3A1'],
		// A dispute names the payment, not the order: the store finds the order by it.
		'disputed'                   => ['charge.dispute.created', PaymentEventType::Disputed, '', 3998, 'dp_1A', 'pi_3A1'],
		'dispute won'                => ['charge.dispute.closed.won', PaymentEventType::DisputeWon, '', 3998, 'dp_1A', 'pi_3A1'],
		'dispute lost'               => ['charge.dispute.closed.lost', PaymentEventType::DisputeLost, '', 3998, 'dp_1A', 'pi_3A1'],
	]);

	it('carries the customer on a paid event', function (): void {
		expect(stripeProvider(new RecordingHttpClient())->parseWebhook(stripeWebhook('checkout.session.completed'))->customerRef)->toBe('cus_A1');
	});

	it('has no use for an event that moves nothing', function (string $fixture): void {
		expect(stripeProvider(new RecordingHttpClient())->parseWebhook(stripeWebhook($fixture)))->toBeNull();
	})->with([
		// The page was completed but the bank has not paid yet: the later event says how it went.
		'completed, not yet paid' => ['checkout.session.completed.unpaid'],
		'a dispute warning closed' => ['charge.dispute.closed.warning_closed'],
		'another kind of event'   => ['customer.created'],
	]);

	it('makes no request while reading one', function (): void {
		$http = new RecordingHttpClient();
		stripeProvider($http)->parseWebhook(stripeWebhook('charge.refunded.no-list'));

		expect($http->requests)->toBe([]);
	});

	it('refuses a body that is not an event', function (string $body): void {
		$factory = new Psr17Factory();
		$request = $factory->createServerRequest('POST', '/commerce/webhook/stripe')->withBody($factory->createStream($body));

		expect(stripeProvider(new RecordingHttpClient())->parseWebhook($request))->toBeNull();
	})->with(['', 'not json', '[]', '{"type":"checkout.session.completed"}', '{"id":"evt_1","type":"checkout.session.completed","data":{"object":"nope"}}']);
});

describe('the webhook signature', function (): void {
	beforeEach(function (): void {
		$this->now    = 1795000000;
		$this->body   = '{"id":"evt_1","type":"checkout.session.completed"}';
		$this->verify = function (string $header, ?string $body = null, string $secret = STRIPE_SECRET): bool {
			$factory = new Psr17Factory();
			$request = $factory->createServerRequest('POST', '/commerce/webhook/stripe')
				->withHeader('Stripe-Signature', $header)
				->withBody($factory->createStream($body ?? $this->body));

			return (new StripeWebhookVerifier($secret, fn (): int => $this->now))->verify($request);
		};
		$this->sign = fn (int $t, ?string $body = null, string $secret = STRIPE_SECRET): string => hash_hmac('sha256', $t . '.' . ($body ?? $this->body), $secret);
	});

	it('accepts a body signed with the endpoint\'s secret', function (): void {
		expect(($this->verify)("t={$this->now},v1=" . ($this->sign)($this->now)))->toBeTrue();
	});

	it('accepts it when any of several signatures matches, as during a secret roll', function (): void {
		expect(($this->verify)("t={$this->now},v1=" . str_repeat('0', 64) . ',v1=' . ($this->sign)($this->now) . ',v0=ignored'))->toBeTrue();
	});

	it('refuses anything else', function (Closure $header, ?string $body, string $secret): void {
		expect(($this->verify)($header($this), $body, $secret))->toBeFalse();
	})->with([
		'a changed body'        => [fn ($t) => "t={$t->now},v1=" . ($t->sign)($t->now), '{"id":"evt_1","type":"checkout.session.completed","amount":1}', STRIPE_SECRET],
		'another secret'        => [fn ($t) => "t={$t->now},v1=" . ($t->sign)($t->now, null, 'whsec_other'), null, STRIPE_SECRET],
		'no secret configured'  => [fn ($t) => "t={$t->now},v1=" . ($t->sign)($t->now, null, ''), null, ''],
		'301 seconds old'       => [fn ($t) => 't=' . ($t->now - 301) . ',v1=' . ($t->sign)($t->now - 301), null, STRIPE_SECRET],
		'301 seconds ahead'     => [fn ($t) => 't=' . ($t->now + 301) . ',v1=' . ($t->sign)($t->now + 301), null, STRIPE_SECRET],
		'only the old scheme'   => [fn ($t) => "t={$t->now},v0=" . ($t->sign)($t->now), null, STRIPE_SECRET],
		'no timestamp'          => [fn ($t) => 'v1=' . ($t->sign)($t->now), null, STRIPE_SECRET],
		'a timestamp of words'  => [fn ($t) => 't=now,v1=' . ($t->sign)($t->now), null, STRIPE_SECRET],
		'no header'             => [fn () => '', null, STRIPE_SECRET],
		'not a signature'       => [fn ($t) => "t={$t->now},v1=zzzz", null, STRIPE_SECRET],
	]);

	it('accepts one exactly at the edge of the five minutes', function (): void {
		$t = $this->now - 300;

		expect(($this->verify)("t={$t},v1=" . ($this->sign)($t)))->toBeTrue();
	});
});

describe('being ready', function (): void {
	it('needs both the secret key and the webhook secret, in their right shapes', function (string $key, string $secret, bool $ready): void {
		expect(stripeProvider(new RecordingHttpClient(), $key, $secret)->isConfigured())->toBe($ready);
	})->with([
		'both'                  => [STRIPE_KEY, STRIPE_SECRET, true],
		'a live key'            => ['sk_live_51AbCdEfGhIjKlMnOp', STRIPE_SECRET, true],
		'a restricted key'      => ['rk_test_51AbCdEfGhIjKlMnOp', STRIPE_SECRET, true],
		'no key'                => ['', STRIPE_SECRET, false],
		'no webhook secret'     => [STRIPE_KEY, '', false],
		'a publishable key'     => ['pk_test_51AbCdEfGhIjKlMnOp', STRIPE_SECRET, false],
		'not a webhook secret'  => [STRIPE_KEY, 'secret', false],
	]);

	it('says what it is and what it can do', function (): void {
		$provider = stripeProvider(new RecordingHttpClient());

		expect($provider->id())->toBe('stripe')
			->and($provider->label())->toBe('Stripe')
			->and($provider->supportsCurrency('USD'))->toBeTrue()
			->and($provider->supportsCurrency('usd'))->toBeTrue()
			->and($provider->supportsCurrency('dollars'))->toBeFalse()
			->and($provider->capabilities()->refunds)->toBeTrue()
			->and($provider->capabilities()->subscriptions)->toBeFalse()
			// Without a publishable key it takes payment on Stripe's own page.
			->and($provider->capabilities()->embedded)->toBeFalse()
			->and($provider)->toBeInstanceOf(EmbeddedCheckoutProvider::class);
	});
});

describe('refunds', function (): void {
	it('asks Stripe to refund the payment, once', function (): void {
		$http   = new RecordingHttpClient(new HttpResponse(200, '{"id":"re_3A1c","status":"succeeded"}'));
		$result = stripeProvider($http)->refund(new RefundRequest(ORDER_ID, 'pi_3A1', Money::of(1000, 'USD'), 'Wrong size', 'refund-' . ORDER_ID . '-1'));

		expect($result->accepted)->toBeTrue()
			->and($result->providerRefundRef)->toBe('re_3A1c')
			->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/refunds')
			->and(stripeForm($http))->toBe(['payment_intent' => 'pi_3A1', 'amount' => '1000', 'reason' => 'requested_by_customer', 'metadata' => ['orderId' => ORDER_ID, 'reason' => 'Wrong size']])
			->and(stripeHeaders($http))->toContain('Idempotency-Key: refund-' . ORDER_ID . '-1');
	});

	it('passes on a reason Stripe knows', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(200, '{"id":"re_1"}'));
		stripeProvider($http)->refund(new RefundRequest(ORDER_ID, 'pi_3A1', Money::of(1000, 'USD'), 'duplicate', 'k'));

		expect(stripeForm($http)['reason'])->toBe('duplicate');
	});

	it('says it was not accepted when Stripe refuses, without throwing', function (): void {
		$http   = new RecordingHttpClient(new HttpResponse(400, (string)json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded']])));
		$result = stripeProvider($http)->refund(new RefundRequest(ORDER_ID, 'pi_3A1', Money::of(1000, 'USD'), '', 'k'));

		expect($result->accepted)->toBeFalse()->and($result->error)->toBe('charge_already_refunded');
	});
});

describe('asking Stripe where a payment stands', function (): void {
	it('reads a paid session as a paid event', function (): void {
		$http  = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'cs_test_a1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 3998, 'currency' => 'usd', 'client_reference_id' => ORDER_ID, 'payment_intent' => 'pi_3A1'])));
		$event = stripeProvider($http)->fetchPayment('cs_test_a1');

		expect($http->requests[0]['method'])->toBe('GET')
			->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions/cs_test_a1')
			->and($event?->type)->toBe(PaymentEventType::Paid)
			->and($event?->orderId)->toBe(ORDER_ID)
			->and($event?->amount->amount())->toBe(3998)
			->and($event?->eventId)->toBe('session-cs_test_a1-paid');
	});

	it('reads an expired session as expired, and an open one as nothing yet', function (string $status, ?PaymentEventType $type): void {
		$http  = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'cs_test_a1', 'payment_status' => 'unpaid', 'status' => $status, 'amount_total' => 3998, 'currency' => 'usd', 'client_reference_id' => ORDER_ID])));
		$event = stripeProvider($http)->fetchPayment('cs_test_a1');

		expect($event?->type)->toBe($type);
	})->with([['expired', PaymentEventType::Expired], ['open', null]]);

	it('is nothing when Stripe cannot be asked', function (): void {
		expect(stripeProvider(new RecordingHttpClient())->fetchPayment('cs_test_a1'))->toBeNull()
			->and(stripeProvider(new RecordingHttpClient())->fetchPayment(''))->toBeNull();
	});
});

describe('the extension', function (): void {
	beforeEach(function (): void {
		/**
		 * @param array<string,mixed> $settings the extension's own settings
		 */
		$this->context = function (array $settings): ExtensionContext {
			$storage = $this->createMock(StorageFilesystemAdapter::class);
			$storage->method('fileExists')->willReturn($settings !== []);
			$storage->method('read')->willReturn((string)json_encode($settings));

			// What the provider asks the site for, once a checkout needs it.
			$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
			$config->api = '/shop';
			$container   = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($config);

			return new ExtensionContext(
				ExtensionManifest::fromArray(['id' => 'totalcms/stripe', 'name' => 'Stripe', 'version' => '1.0.0']),
				dirname(__DIR__, 4) . '/resources/extensions/totalcms/stripe',
				$container,
				new ExtensionSettingsManager($storage),
				new NullLogger(),
			);
		};
	});

	it('registers the Stripe provider, ready when the mode in use has its keys', function (array $settings, bool $ready): void {
		$context = ($this->context)($settings);
		(new Extension())->register($context);
		$providers = $context->getRegisteredPaymentProviders();

		expect($providers)->toHaveCount(1)
			->and($providers[0]->id())->toBe('stripe')
			->and($providers[0]->isConfigured())->toBe($ready)
			->and($context->getCapabilities())->toBe(['commerce:payments' => true]);
	})->with([
		'nothing set'                 => [[], false],
		'test mode, test keys'        => [['mode' => 'test', 'testSecretKey' => STRIPE_KEY, 'testWebhookSecret' => STRIPE_SECRET], true],
		'live mode, only test keys'   => [['mode' => 'live', 'testSecretKey' => STRIPE_KEY, 'testWebhookSecret' => STRIPE_SECRET], false],
		'live mode, live keys'        => [['mode' => 'live', 'liveSecretKey' => 'sk_live_51AbCdEfGhIjKlMnOp', 'liveWebhookSecret' => 'whsec_live'], true],
		'a live key in test mode'     => [['mode' => 'test', 'testSecretKey' => 'sk_live_51AbCdEfGhIjKlMnOp', 'testWebhookSecret' => STRIPE_SECRET], false],
	]);

	it('hands the provider the publishable key of the mode in use', function (array $settings, string $expected): void {
		$context = ($this->context)(['testSecretKey' => STRIPE_KEY, 'testWebhookSecret' => STRIPE_SECRET, 'liveSecretKey' => 'sk_live_51AbCdEfGhIjKlMnOp', 'liveWebhookSecret' => 'whsec_live'] + $settings);
		(new Extension())->register($context);
		$provider = $context->getRegisteredPaymentProviders()[0];

		expect((new ReflectionProperty($provider, 'publishableKey'))->getValue($provider))->toBe($expected);
	})->with([
		'test mode'                    => [['mode' => 'test', 'testPublishableKey' => STRIPE_PUBLIC, 'livePublishableKey' => 'pk_live_51Ab'], STRIPE_PUBLIC],
		'live mode'                    => [['mode' => 'live', 'testPublishableKey' => STRIPE_PUBLIC, 'livePublishableKey' => 'pk_live_51Ab'], 'pk_live_51Ab'],
		'a live key in test mode'      => [['mode' => 'test', 'testPublishableKey' => 'pk_live_51Ab'], ''],
		'none'                         => [['mode' => 'test'], ''],
	]);

	it('is ready without the publishable key, and can show its form on the store\'s page only with one of the mode in use', function (array $settings, bool $canEmbed): void {
		$context = ($this->context)(['mode' => 'test', 'testSecretKey' => STRIPE_KEY, 'testWebhookSecret' => STRIPE_SECRET] + $settings);
		(new Extension())->register($context);
		$provider = $context->getRegisteredPaymentProviders()[0];

		expect($provider->isConfigured())->toBeTrue()
			->and($provider->capabilities()->embedded)->toBe($canEmbed);
	})->with([
		'no publishable key'      => [[], false],
		'a live key in test mode' => [['testPublishableKey' => 'pk_live_51AbCdEfGhIjKlMnOp'], false],
		'with it'                 => [['testPublishableKey' => STRIPE_PUBLIC], true],
	]);

	it('points the page at its own module, under the path the site is served from', function (): void {
		$context = ($this->context)(['mode' => 'test', 'testSecretKey' => STRIPE_KEY, 'testWebhookSecret' => STRIPE_SECRET, 'testPublishableKey' => STRIPE_PUBLIC]);
		(new Extension())->register($context);
		$embed = $context->getRegisteredPaymentProviders()[0]->embed(new CheckoutSession('cs_test_e1', '', 'cs_test_e1_secret'));

		expect($embed->moduleUrl)->toMatch('#^/shop/api/ext/totalcms/stripe/assets/embed\.js\?v=\d{9,}$#')
			->and($embed->config['publishableKey'])->toBe(STRIPE_PUBLIC);
	});

	it('ships the module the pay page imports', function (): void {
		$file = dirname(__DIR__, 4) . '/resources/extensions/totalcms/stripe/assets/embed.js';

		expect(is_file($file))->toBeTrue()->and((string)file_get_contents($file))->toContain('export async function mount(');
	});

	it('ships a manifest that needs Pro and the Commerce add-on, and keeps its keys secret', function (): void {
		$dir      = dirname(__DIR__, 4) . '/resources/extensions/totalcms/stripe';
		$manifest = json_decode((string)file_get_contents($dir . '/extension.json'), true);
		$settings = json_decode((string)file_get_contents($dir . '/settings.json'), true)['properties'];

		expect($manifest['id'])->toBe('totalcms/stripe')
			->and($manifest['min_edition'])->toBe('pro')
			->and($manifest['entitlement'])->toBe('totalcms/commerce')
			->and(is_file($dir . '/' . $manifest['icon']))->toBeTrue()
			->and(array_keys($settings))->toBe(['mode', 'testPublishableKey', 'testSecretKey', 'testWebhookSecret', 'livePublishableKey', 'liveSecretKey', 'liveWebhookSecret', 'statementDescriptor'])
			->and($settings['testPublishableKey']['field'])->toBe('text')
			->and($settings['livePublishableKey']['field'])->toBe('text');
		foreach (['testSecretKey', 'testWebhookSecret', 'liveSecretKey', 'liveWebhookSecret'] as $key) {
			expect($settings[$key]['field'])->toBe('secret');
		}
	});
});
