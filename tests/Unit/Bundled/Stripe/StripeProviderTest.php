<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\Stripe\Command\TaxCommand;
use TotalCMS\Bundled\Stripe\Extension;
use TotalCMS\Bundled\Stripe\StripeClient;
use TotalCMS\Bundled\Stripe\StripeProvider;
use TotalCMS\Bundled\Stripe\StripeWebhookVerifier;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckoutProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
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
	// A dispute names the payment, not the order, and it can reach the store
	// before the payment event that would let the store find the order by it.
	// The order's id is on the payment at Stripe, where checkout put it.
	it('reads the order of a new dispute from the payment at Stripe', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'pi_3A1', 'metadata' => ['orderId' => ORDER_ID, 'orderNumber' => '1001']])));

		$event = stripeProvider($http)->parseWebhook(stripeWebhook('charge.dispute.created'));

		expect($event->orderId)->toBe(ORDER_ID)
			->and($event->type)->toBe(PaymentEventType::Disputed)
			->and($http->requests)->toHaveCount(1)
			->and($http->requests[0]['method'])->toBe('GET')
			->and($http->requests[0]['url'])->toBe(StripeClient::BASE . '/payment_intents/pi_3A1');
	});

	it('names no order for a dispute of a payment the store did not take, or when Stripe cannot be asked', function (HttpResponse ...$answers): void {
		$event = stripeProvider(new RecordingHttpClient(...$answers))->parseWebhook(stripeWebhook('charge.dispute.created'));

		expect($event->orderId)->toBe('')
			->and($event->providerPaymentRef)->toBe('pi_3A1');
	})->with([
		"another system's payment" => [new HttpResponse(200, '{"id":"pi_3A1","metadata":{}}')],
		'Stripe is unreachable'    => [],
		'Stripe refuses'           => [new HttpResponse(404, '{"error":{"message":"No such payment_intent"}}')],
	]);

	it('does not ask Stripe about a dispute that is closing: by then the order is found by its payment', function (string $fixture): void {
		$http = new RecordingHttpClient();

		stripeProvider($http)->parseWebhook(stripeWebhook($fixture));

		expect($http->requests)->toBe([]);
	})->with(['charge.dispute.closed.won', 'charge.dispute.closed.lost']);

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

/*
 * Stripe Tax: the store leaves the tax to Stripe. Stripe finds out where the
 * buyer is, adds the tax on its page, and says how much on the session.
 */
describe('leaving the tax to Stripe', function (): void {
	beforeEach(function (): void {
		$this->taxed = static fn (array $with = []): CheckoutRequest => stripeCheckout(null, 3998, ['providerTax' => true] + $with);
		$this->shipTo = new Address(name: 'Ada Buyer', line1: '1 Main St', line2: 'Apt 2', city: 'Boise', region: 'ID', postalCode: '83702', country: 'US');
	});

	it('says it can work out tax', function (): void {
		expect(stripeProvider(new RecordingHttpClient())->capabilities()->tax)->toBeTrue();
	});

	it('switches Stripe\'s tax on, and says the tax goes on top of each price', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http)->createCheckout(($this->taxed)());
		$form = stripeForm($http);

		expect($form['automatic_tax'])->toBe(['enabled' => 'true'])
			->and($form['line_items'][0]['price_data'])->toBe(['currency' => 'usd', 'unit_amount' => '1999', 'product_data' => ['name' => 'Tee'], 'tax_behavior' => 'exclusive'])
			// Still the order's total before tax, and the same buyer.
			->and($form['customer_email'])->toBe('buyer@example.com')
			->and($form)->not->toHaveKey('customer');
	});

	it('says the prices already hold the tax when the store says so', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http)->createCheckout(($this->taxed)(['taxIncluded' => true]));

		expect(stripeForm($http)['line_items'][0]['price_data']['tax_behavior'])->toBe('inclusive');
	});

	it('marks shipping as shipping, which is taxed by its own rules', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http)->createCheckout(stripeCheckout(null, 3998 + 500, ['providerTax' => true, 'shipping' => Money::of(500, 'USD')]));
		$shipping = stripeForm($http)['line_items'][1]['price_data'];

		expect($shipping['product_data'])->toBe(['name' => 'Shipping', 'tax_code' => 'txcd_92010001'])
			->and($shipping['tax_behavior'])->toBe('exclusive');
	});

	it('tells Stripe a product the store does not tax is not taxable', function (): void {
		$http  = new RecordingHttpClient(stripeSession());
		$usd   = static fn (int $amount): Money => Money::of($amount, 'USD');
		$lines = [new CheckoutLineSummary('Tee', 1, $usd(1999), $usd(0), $usd(0)), new CheckoutLineSummary('Gift Card', 1, $usd(2000), $usd(0), $usd(0), taxable: false)];
		stripeProvider($http)->createCheckout(stripeCheckout($lines, 3999, ['providerTax' => true]));
		$items = stripeForm($http)['line_items'];

		expect($items[0]['price_data']['product_data'])->toBe(['name' => 'Tee'])
			->and($items[1]['price_data']['product_data'])->toBe(['name' => 'Gift Card', 'tax_code' => 'txcd_00000000']);
	});

	it('leaves all of that off a checkout the store taxed itself', function (): void {
		$http = new RecordingHttpClient(stripeSession());
		stripeProvider($http)->createCheckout(stripeCheckout(null, 3998 + 500, ['shipping' => Money::of(500, 'USD')]));
		$form = stripeForm($http);

		expect($form)->not->toHaveKey('automatic_tax')
			->and($form['line_items'][1]['price_data'])->toBe(['currency' => 'usd', 'unit_amount' => '500', 'product_data' => ['name' => 'Shipping']]);
	});

	describe('an order that ships', function (): void {
		it('gives Stripe the address it ships to, as a customer, so the tax is for where it is going', function (): void {
			$http = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'cus_A1'])), stripeSession());
			stripeProvider($http)->createCheckout(($this->taxed)(['shipTo' => $this->shipTo]));

			$customer = stripeForm($http, 0);
			$session  = stripeForm($http, 1);

			expect($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/customers')
				->and($customer)->toBe([
					'email'    => 'buyer@example.com',
					'name'     => 'Ada Buyer',
					'shipping' => ['name' => 'Ada Buyer', 'address' => ['line1' => '1 Main St', 'line2' => 'Apt 2', 'city' => 'Boise', 'state' => 'ID', 'postal_code' => '83702', 'country' => 'US']],
					'metadata' => ['orderId' => ORDER_ID],
				])
				->and(stripeHeaders($http, 0))->toContain('Idempotency-Key: checkout-' . ORDER_ID . '-customer')
				->and($session['customer'])->toBe('cus_A1')
				// The billing address Stripe may ask for is kept on that customer.
				->and($session['customer_update'])->toBe(['address' => 'auto'])
				->and($session)->not->toHaveKey('customer_email')
				->and($session['automatic_tax'])->toBe(['enabled' => 'true']);
		});

		it('names the customer by the email when the address has no name', function (): void {
			$http = new RecordingHttpClient(new HttpResponse(200, (string)json_encode(['id' => 'cus_A1'])), stripeSession());
			stripeProvider($http)->createCheckout(($this->taxed)(['shipTo' => new Address(country: 'US', region: 'ID')]));
			$customer = stripeForm($http, 0);

			expect($customer)->not->toHaveKey('name')
				->and($customer['shipping'])->toBe(['name' => 'buyer@example.com', 'address' => ['state' => 'ID', 'country' => 'US']]);
		});
	});

	describe('when Stripe will not start the checkout with tax', function (): void {
		// Stripe Tax not set up, a setting the account lacks: the buyer must
		// not be the one who finds out. The sale goes ahead without tax and
		// the owner is told in the log.
		it('starts it without, under a key of its own, and says so in the log', function (): void {
			$logger = new RecordingLogger();
			$http   = new RecordingHttpClient(
				new HttpResponse(400, (string)json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'stripe_tax_inactive', 'message' => 'no']])),
				stripeSession(),
			);
			$session = stripeProvider($http, logger: $logger)->createCheckout(($this->taxed)());
			$second  = stripeForm($http, 1);

			expect($session->providerSessionRef)->toBe('cs_test_a1')
				->and($second)->not->toHaveKey('automatic_tax')
				->and($second['line_items'][0]['price_data'])->not->toHaveKey('tax_behavior')
				->and($second['customer_email'])->toBe('buyer@example.com')
				->and(stripeHeaders($http, 1))->toContain('Idempotency-Key: checkout-' . ORDER_ID . '-notax')
				->and($logger->records)->toHaveCount(1)
				->and($logger->records[0]['level'])->toBe('warning')
				->and($logger->records[0]['context'])->toBe(['orderId' => ORDER_ID, 'code' => 'stripe_tax_inactive']);
		});

		it('does the same when the customer for the address cannot be made', function (): void {
			$http = new RecordingHttpClient(new HttpResponse(400, (string)json_encode(['error' => ['type' => 'invalid_request_error']])), stripeSession());
			stripeProvider($http)->createCheckout(($this->taxed)(['shipTo' => $this->shipTo]));

			expect($http->requests)->toHaveCount(2)
				->and($http->requests[1]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions')
				->and(stripeForm($http, 1))->not->toHaveKey('customer');
		});

		it('does not take "Stripe could not be reached" as a no: that checkout fails, as any would', function (): void {
			$http = new RecordingHttpClient();

			expect(fn () => stripeProvider($http)->createCheckout(($this->taxed)()))->toThrow(ProviderException::class)
				->and($http->requests)->toHaveCount(1);
		});
	});

	describe('a paid session', function (): void {
		beforeEach(function (): void {
			$this->session = static fn (array $with): HttpResponse => new HttpResponse(200, (string)json_encode($with + [
				'id' => 'cs_test_a1', 'payment_status' => 'paid', 'status' => 'complete', 'currency' => 'usd', 'client_reference_id' => ORDER_ID, 'payment_intent' => 'pi_3A1',
			]));
		});

		it('carries the tax Stripe worked out and the total the store had asked for', function (): void {
			$http  = new RecordingHttpClient(($this->session)(['amount_subtotal' => 3998, 'amount_total' => 4238, 'automatic_tax' => ['enabled' => true, 'status' => 'complete'], 'total_details' => ['amount_tax' => 240, 'amount_discount' => 0, 'amount_shipping' => 0]]));
			$event = stripeProvider($http)->fetchPayment('cs_test_a1');

			expect($event?->amount->amount())->toBe(4238)
				->and($event?->tax?->amount())->toBe(240)
				->and($event?->tax?->currency())->toBe('USD')
				->and($event?->orderTotal?->amount())->toBe(3998);
		});

		it('carries a tax of nothing when Stripe worked out none', function (): void {
			$http  = new RecordingHttpClient(($this->session)(['amount_subtotal' => 3998, 'amount_total' => 3998, 'automatic_tax' => ['enabled' => true, 'status' => 'complete'], 'total_details' => ['amount_tax' => 0]]));
			$event = stripeProvider($http)->fetchPayment('cs_test_a1');

			expect($event?->tax?->amount())->toBe(0)
				->and($event?->orderTotal?->amount())->toBe(3998);
		});

		it('says nothing of tax for a session Stripe was not asked to tax', function (): void {
			$http  = new RecordingHttpClient(($this->session)(['amount_subtotal' => 3998, 'amount_total' => 3998, 'automatic_tax' => ['enabled' => false, 'status' => null], 'total_details' => ['amount_tax' => 0]]));
			$event = stripeProvider($http)->fetchPayment('cs_test_a1');

			expect($event?->tax)->toBeNull()
				->and($event?->orderTotal)->toBeNull();
		});
	});

	describe('whether Stripe Tax is ready', function (): void {
		it('is ready when Stripe says its settings are active, and counts the places tax is collected', function (): void {
			$http = new RecordingHttpClient(
				new HttpResponse(200, (string)json_encode(['object' => 'tax.settings', 'status' => 'active', 'status_details' => ['active' => []]])),
				new HttpResponse(200, (string)json_encode(['object' => 'list', 'data' => [['id' => 'taxreg_1'], ['id' => 'taxreg_2']], 'has_more' => false])),
			);
			$status = stripeProvider($http)->taxStatus();

			expect($status)->toBe(['ready' => true, 'missing' => [], 'registrations' => 2, 'error' => ''])
				->and($http->methods())->toBe(['GET', 'GET'])
				->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/tax/settings')
				->and($http->requests[1]['url'])->toBe('https://api.stripe.com/v1/tax/registrations?status=active&limit=100');
		});

		it('says what Stripe is still missing', function (): void {
			$http = new RecordingHttpClient(
				new HttpResponse(200, (string)json_encode(['status' => 'pending', 'status_details' => ['pending' => ['missing_fields' => ['head_office']]]])),
				new HttpResponse(200, (string)json_encode(['data' => []])),
			);

			expect(stripeProvider($http)->taxStatus())->toBe(['ready' => false, 'missing' => ['head_office'], 'registrations' => 0, 'error' => '']);
		});

		it('says so when Stripe cannot be asked', function (): void {
			expect(stripeProvider(new RecordingHttpClient())->taxStatus())->toBe(['ready' => false, 'missing' => [], 'registrations' => 0, 'error' => 'network']);
		});
	});
});

/*
 * `tcms stripe:tax`: the owner finds out what Stripe Tax is missing, so a
 * buyer never does.
 */
describe('tcms stripe:tax', function (): void {
	beforeEach(function (): void {
		$this->run = function (array $responses, array $store = [], string $key = STRIPE_KEY): CommandTester {
			$http             = new RecordingHttpClient(...$responses);
			$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
			$config->commerce = $store + ['taxMode' => 'exclusive', 'taxBy' => 'provider'];
			$tester           = new CommandTester(new TaxCommand(stripeProvider($http, $key), static fn (): CommerceSettings => new CommerceSettings($config)));
			$tester->execute([]);
			$this->http = $http;

			return $tester;
		};
		$this->settings      = static fn (array $settings): HttpResponse => new HttpResponse(200, (string)json_encode($settings));
		$this->active        = ($this->settings)(['status' => 'active', 'status_details' => ['active' => []]]);
		$this->registrations = static fn (int $count): HttpResponse => new HttpResponse(200, (string)json_encode(['data' => array_fill(0, $count, ['id' => 'taxreg'])]));
	});

	it('says Stripe Tax is ready when it is', function (): void {
		$tester = ($this->run)([$this->active, ($this->registrations)(2)]);

		expect($tester->getStatusCode())->toBe(0)
			->and($tester->getDisplay())->toContain('test key', 'Stripe Tax is set up', 'collected in 2 places', 'Ready');
	});

	it('names what Stripe still needs, in plain words', function (): void {
		$tester = ($this->run)([($this->settings)(['status' => 'pending', 'status_details' => ['pending' => ['missing_fields' => ['head_office']]]]), ($this->registrations)(1)]);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('not set up', 'your head office address')
			->and($tester->getDisplay())->not->toContain('Ready');
	});

	it('warns that with no registration Stripe works out no tax', function (): void {
		$tester = ($this->run)([$this->active, ($this->registrations)(0)]);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('collected nowhere', 'Registrations');
	});

	it('says when the store is not set to leave the tax to the provider', function (array $store, string $words): void {
		$tester = ($this->run)([$this->active, ($this->registrations)(1)], $store);

		expect($tester->getStatusCode())->toBe(0)
			->and($tester->getDisplay())->toContain('Settings → Commerce', $words)
			->and($tester->getDisplay())->not->toContain('leaves the tax to the payment provider');
	})->with([
		'it works out its own tax' => [['taxBy' => 'table'], 'Tax Worked Out By'],
		'it charges no tax'        => [['taxMode' => 'none'], 'No tax'],
	]);

	it('says so when the store does leave it to the provider', function (): void {
		$tester = ($this->run)([$this->active, ($this->registrations)(1)]);

		expect($tester->getDisplay())->toContain('leaves the tax to the payment provider');
	});

	it('says so when Stripe cannot be asked, and never shows the key', function (): void {
		$tester = ($this->run)([]);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('could not be asked')
			->and($tester->getDisplay())->not->toContain(STRIPE_KEY);
	});

	it('asks nothing of Stripe without a secret key', function (): void {
		$tester = ($this->run)([], [], '');

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('No Stripe secret key')
			->and($this->http->requests)->toBe([]);
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
			// Both whatever the settings say: a capability gained later, once the
			// keys are in, would start switched off on that site.
			->and($context->getCapabilities())->toBe(['cli:commands' => true, 'commerce:payments' => true]);
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

	it('ships the command that checks Stripe Tax', function (): void {
		$context = ($this->context)([]);
		(new Extension())->register($context);

		expect(array_map(static fn ($command): string => (string)$command->getName(), $context->getRegisteredCommands()))->toContain('stripe:tax', 'stripe:listen');
	});

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
