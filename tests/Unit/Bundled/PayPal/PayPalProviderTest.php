<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\PayPal\Extension;
use TotalCMS\Bundled\PayPal\PayPalClient;
use TotalCMS\Bundled\PayPal\PayPalProvider;
use TotalCMS\Bundled\PayPal\PayPalWebhookVerifier;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckoutProvider;
use TotalCMS\Domain\Commerce\Payment\OrderView;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Payment\SubscriptionProvider;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/paypal/Extension.php';

/*
 * The PayPal provider, offline. Every request it would send is recorded, so
 * the tests can hold what goes to PayPal: the total the store worked out,
 * the order it is for, and never a secret in an error. The first request of
 * any conversation with PayPal asks for an access token.
 */

const PAYPAL_CLIENT   = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';
const PAYPAL_SECRET   = 'EJ-a-secret-nobody-should-ever-see';
const PAYPAL_WEBHOOK  = '8PT597110X687430LKGECATA';
const PAYPAL_ORDER_ID = '0123456789abcdef0123456789abcdef';
const PAYPAL_REF      = '3P199480AE683730M';
const PAYPAL_CAPTURE  = '8RU61172JS455403V';

function paypalAnswer(array $body, int $status = 200): HttpResponse
{
	return new HttpResponse($status, (string)json_encode($body));
}

function paypalToken(): HttpResponse
{
	return paypalAnswer(['access_token' => 'A21AAtoken', 'token_type' => 'Bearer', 'expires_in' => 32400]);
}

/** A client whose first answer is an access token, then $answers in order. */
function paypalHttp(HttpResponse ...$answers): RecordingHttpClient
{
	return new RecordingHttpClient(paypalToken(), ...$answers);
}

function paypalProvider(RecordingHttpClient $http, string $clientId = PAYPAL_CLIENT, string $secret = PAYPAL_SECRET, string $webhookId = PAYPAL_WEBHOOK, ?RecordingLogger $logger = null, bool $live = false): PayPalProvider
{
	return new PayPalProvider(new PayPalClient(static fn (): HttpClientInterface => $http, $clientId, $secret, $live), $clientId, $secret, $webhookId, $logger ?? new NullLogger());
}

/** @param list<CheckoutLineSummary>|null $lines */
function paypalCheckout(?array $lines = null, int $total = 3998, array $with = [], string $currency = 'USD'): CheckoutRequest
{
	$money = static fn (int $amount): Money => Money::of($amount, $currency);

	return new CheckoutRequest(...$with + [
		'orderId'        => PAYPAL_ORDER_ID,
		'orderNumber'    => 1001,
		'total'          => $money($total),
		'lines'          => $lines ?? [new CheckoutLineSummary('Tee', 2, $money(1999), $money(0), $money(0))],
		'shipping'       => $money(0),
		'tax'            => $money(0),
		'email'          => 'buyer@example.com',
		'successUrl'     => 'https://shop.example/commerce/return/' . PAYPAL_ORDER_ID . '?t=abc',
		'cancelUrl'      => 'https://shop.example/commerce/cancel/' . PAYPAL_ORDER_ID . '?t=abc',
		'returnUrl'      => '',
		'metadata'       => ['orderId' => PAYPAL_ORDER_ID, 'orderNumber' => '1001'],
		'idempotencyKey' => 'checkout-' . PAYPAL_ORDER_ID,
	]);
}

/** PayPal's answer to a new order: where the buyer goes to pay. */
function paypalCreated(): HttpResponse
{
	return paypalAnswer(['id' => PAYPAL_REF, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
		['href' => 'https://api.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_REF, 'rel' => 'self', 'method' => 'GET'],
		['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . PAYPAL_REF, 'rel' => 'payer-action', 'method' => 'GET'],
	]]);
}

/** PayPal's order once its payment has been captured. */
function paypalCaptured(string $status = 'COMPLETED', string $value = '39.98'): HttpResponse
{
	return paypalAnswer(['id' => PAYPAL_REF, 'status' => 'COMPLETED', 'purchase_units' => [[
		'reference_id' => PAYPAL_ORDER_ID,
		'custom_id'    => PAYPAL_ORDER_ID,
		'payments'     => ['captures' => [['id' => PAYPAL_CAPTURE, 'status' => $status, 'amount' => ['currency_code' => 'USD', 'value' => $value], 'custom_id' => PAYPAL_ORDER_ID]]],
	]]]);
}

/** The JSON body a recorded request carried. */
function paypalBody(RecordingHttpClient $http, int $index = 1): array
{
	return (array)json_decode((string)($http->requests[$index]['options']['body'] ?? ''), true);
}

/** @return list<string> */
function paypalHeaders(RecordingHttpClient $http, int $index = 1): array
{
	return array_values($http->requests[$index]['options']['headers'] ?? []);
}

function paypalWebhook(string $fixture, array $headers = []): ServerRequestInterface
{
	$factory = new Psr17Factory();
	$body    = (string)file_get_contents(dirname(__DIR__, 3) . "/fixtures/paypal/{$fixture}.json");
	$request = $factory->createServerRequest('POST', 'https://shop.example/commerce/webhook/paypal')->withBody($factory->createStream($body));
	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}

	return $request;
}

function paypalView(string $status = 'pending', string $ref = PAYPAL_REF): OrderView
{
	return new OrderView(PAYPAL_ORDER_ID, 1001, 'buyer@example.com', Money::of(3998, 'USD'), $status, 'paypal', $ref);
}

function paypalReturn(string $query): ServerRequestInterface
{
	return (new Psr17Factory())->createServerRequest('GET', 'https://shop.example/commerce/return/' . PAYPAL_ORDER_ID . '?' . $query)->withQueryParams((static function () use ($query): array {
		parse_str($query, $params);

		return $params;
	})());
}

describe('talking to PayPal', function (): void {
	it('asks for an access token with the client id and secret, then sends it with each request', function (): void {
		$http = paypalHttp(paypalCreated());
		paypalProvider($http)->createCheckout(paypalCheckout());

		expect($http->requests[0]['method'])->toBe('POST')
			->and($http->requests[0]['url'])->toBe('https://api-m.sandbox.paypal.com/v1/oauth2/token')
			->and($http->requests[0]['options']['body'])->toBe('grant_type=client_credentials')
			->and(paypalHeaders($http, 0))->toContain('Authorization: Basic ' . base64_encode(PAYPAL_CLIENT . ':' . PAYPAL_SECRET))
			->and(paypalHeaders($http))->toContain('Authorization: Bearer A21AAtoken', 'Content-Type: application/json');
	});

	it('asks for the token once, however many requests follow', function (): void {
		$http     = paypalHttp(paypalCreated(), paypalCaptured());
		$provider = paypalProvider($http);
		$provider->createCheckout(paypalCheckout());
		$provider->fetchPayment(PAYPAL_REF);

		expect(array_column($http->requests, 'url'))->toBe([
			'https://api-m.sandbox.paypal.com/v1/oauth2/token',
			'https://api-m.sandbox.paypal.com/v2/checkout/orders',
			'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_REF,
		]);
	});

	it('talks to the live service only in live mode', function (): void {
		$http = paypalHttp(paypalCreated());
		paypalProvider($http, live: true)->createCheckout(paypalCheckout());

		expect($http->requests[1]['url'])->toBe('https://api-m.paypal.com/v2/checkout/orders');
	});

	it('turns a refusal into an error that names PayPal\'s issue, and never the secret', function (): void {
		$http = paypalHttp(paypalAnswer(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'AMOUNT_MISMATCH', 'description' => 'no']], 'message' => 'The requested action could not be performed', 'debug_id' => 'f518'], 422));

		try {
			paypalProvider($http)->createCheckout(paypalCheckout());
			$this->fail('expected a ProviderException');
		} catch (ProviderException $e) {
			expect($e->providerCode)->toBe('AMOUNT_MISMATCH')
				->and($e->getMessage())->toContain('422', 'AMOUNT_MISMATCH')
				->and($e->getMessage())->not->toContain(PAYPAL_SECRET, 'A21AAtoken', 'The requested action');
		}
	});

	it('names the error when PayPal gives no issue, and says so when it cannot be reached', function (HttpResponse $answer, string $code): void {
		expect(fn () => paypalProvider(paypalHttp($answer))->createCheckout(paypalCheckout()))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe($code));
	})->with([
		'an error with only a name'  => [paypalAnswer(['name' => 'INTERNAL_SERVER_ERROR'], 500), 'INTERNAL_SERVER_ERROR'],
		'no answer at all'           => [new HttpResponse(0, ''), 'network'],
		'something that is not JSON' => [new HttpResponse(200, '<html>'), 'network'],
	]);

	it('says so when PayPal will not give a token: wrong credentials', function (): void {
		$http = new RecordingHttpClient(paypalAnswer(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401));

		expect(fn () => paypalProvider($http)->createCheckout(paypalCheckout()))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('invalid_client')->and($e->getMessage())->not->toContain(PAYPAL_SECRET))
			->and($http->requests)->toHaveCount(1);
	});
});

describe('starting a checkout', function (): void {
	it('asks PayPal for an order of exactly the total the store worked out', function (): void {
		$http    = paypalHttp(paypalCreated());
		$session = paypalProvider($http)->createCheckout(paypalCheckout());
		$body    = paypalBody($http);

		expect($session->providerSessionRef)->toBe(PAYPAL_REF)
			->and($session->redirectUrl)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=' . PAYPAL_REF)
			->and($body['intent'])->toBe('CAPTURE')
			->and($body['purchase_units'])->toHaveCount(1)
			->and($body['purchase_units'][0])->toBe([
				'reference_id' => PAYPAL_ORDER_ID,
				'custom_id'    => PAYPAL_ORDER_ID,
				'invoice_id'   => '1001-01234567',
				'amount'       => ['currency_code' => 'USD', 'value' => '39.98', 'breakdown' => ['item_total' => ['currency_code' => 'USD', 'value' => '39.98']]],
				'items'        => [['name' => 'Tee', 'quantity' => '2', 'unit_amount' => ['currency_code' => 'USD', 'value' => '19.99']]],
			])
			->and($body['payment_source']['paypal']['experience_context'])->toBe([
				'return_url'          => 'https://shop.example/commerce/return/' . PAYPAL_ORDER_ID . '?t=abc',
				'cancel_url'          => 'https://shop.example/commerce/cancel/' . PAYPAL_ORDER_ID . '?t=abc',
				'user_action'         => 'PAY_NOW',
				// The store has the address an order ships to; PayPal is not asked to collect another.
				'shipping_preference' => 'NO_SHIPPING',
			]);
	});

	it('sends the order\'s own key, so a repeat is answered with the same PayPal order', function (): void {
		$http = paypalHttp(paypalCreated());
		paypalProvider($http)->createCheckout(paypalCheckout());

		expect(paypalHeaders($http))->toContain('PayPal-Request-Id: checkout-' . PAYPAL_ORDER_ID);
	});

	it('writes an amount the way the currency is written: no decimals for yen', function (): void {
		$http = paypalHttp(paypalCreated());
		$yen  = static fn (int $amount): Money => Money::of($amount, 'JPY');
		paypalProvider($http)->createCheckout(paypalCheckout([new CheckoutLineSummary('Tee', 1, $yen(1500), $yen(0), $yen(0))], 1500, [], 'JPY'));

		expect(paypalBody($http)['purchase_units'][0]['amount'])->toBe(['currency_code' => 'JPY', 'value' => '1500', 'breakdown' => ['item_total' => ['currency_code' => 'JPY', 'value' => '1500']]]);
	});

	it('keeps items at full price and takes the discount off in the breakdown, so the sum is the total', function (): void {
		$http = paypalHttp(paypalCreated());
		$usd  = static fn (int $amount): Money => Money::of($amount, 'USD');
		paypalProvider($http)->createCheckout(paypalCheckout([new CheckoutLineSummary('Tee', 2, $usd(1999), $usd(600), $usd(0))], 3398 + 500 + 320, ['shipping' => $usd(500), 'tax' => $usd(320)]));
		$unit = paypalBody($http)['purchase_units'][0];

		expect($unit['amount']['value'])->toBe('42.18')
			->and($unit['amount']['breakdown'])->toBe([
				'item_total' => ['currency_code' => 'USD', 'value' => '39.98'],
				'discount'   => ['currency_code' => 'USD', 'value' => '6.00'],
				'shipping'   => ['currency_code' => 'USD', 'value' => '5.00'],
				'tax_total'  => ['currency_code' => 'USD', 'value' => '3.20'],
			])
			->and($unit['items'][0]['unit_amount']['value'])->toBe('19.99');
	});

	it('cuts a long product name to what PayPal takes', function (): void {
		$http = paypalHttp(paypalCreated());
		$usd  = static fn (int $amount): Money => Money::of($amount, 'USD');
		paypalProvider($http)->createCheckout(paypalCheckout([new CheckoutLineSummary(str_repeat('é', 200), 1, $usd(3998), $usd(0), $usd(0))]));

		expect(mb_strlen(paypalBody($http)['purchase_units'][0]['items'][0]['name']))->toBe(127);
	});

	it('refuses to call PayPal when the parts do not add up to the total', function (): void {
		$http = paypalHttp(paypalCreated());

		expect(fn () => paypalProvider($http)->createCheckout(paypalCheckout(null, 4000)))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('total_mismatch'))
			->and($http->requests)->toBe([]);
	});

	it('refuses a subscription, which it cannot bill, without calling PayPal', function (): void {
		$http = paypalHttp(paypalCreated());

		expect(fn () => paypalProvider($http)->createCheckout(paypalCheckout(null, 3998, ['mode' => 'subscription'])))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('unsupported'))
			->and($http->requests)->toBe([]);
	});

	it('refuses an answer with no order or no page to pay on', function (array $answer): void {
		expect(fn () => paypalProvider(paypalHttp(paypalAnswer($answer)))->createCheckout(paypalCheckout()))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('invalid_response'));
	})->with([
		'no id'                               => [['links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=x']]]],
		'no link'                             => [['id' => PAYPAL_REF, 'links' => [['rel' => 'self', 'href' => 'https://api.sandbox.paypal.com/x']]]],
		'a link somewhere that is not PayPal' => [['id' => PAYPAL_REF, 'links' => [['rel' => 'payer-action', 'href' => 'https://evil.example/pay']]]],
	]);

	it('takes the older "approve" link when PayPal names it that', function (): void {
		$http    = paypalHttp(paypalAnswer(['id' => PAYPAL_REF, 'links' => [['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=' . PAYPAL_REF]]]));
		$session = paypalProvider($http)->createCheckout(paypalCheckout());

		expect($session->redirectUrl)->toBe('https://www.paypal.com/checkoutnow?token=' . PAYPAL_REF);
	});
});

/*
 * PayPal holds the buyer's approval, not their money, until the store
 * captures it. That is done when the buyer comes back.
 */
describe('when the buyer comes back', function (): void {
	it('captures the payment the buyer approved', function (): void {
		$http = paypalHttp(paypalCaptured());
		paypalProvider($http)->completeReturn(paypalView(), paypalReturn('t=abc&token=' . PAYPAL_REF . '&PayerID=ABC'));

		expect($http->requests[1]['method'])->toBe('POST')
			->and($http->requests[1]['url'])->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_REF . '/capture')
			->and($http->requests[1]['options']['body'])->toBe('{}')
			// The same key every time, so coming back twice captures once.
			->and(paypalHeaders($http))->toContain('PayPal-Request-Id: capture-' . PAYPAL_ORDER_ID);
	});

	it('captures nothing for a return that is not about this order\'s payment', function (string $query): void {
		$http = paypalHttp(paypalCaptured());
		paypalProvider($http)->completeReturn(paypalView(), paypalReturn($query));

		expect($http->requests)->toBe([]);
	})->with(['another PayPal order' => ['token=SOMEONE-ELSES'], 'no token' => ['t=abc'], 'an empty token' => ['token=']]);

	it('captures nothing for an order that is no longer waiting to be paid', function (string $status): void {
		$http = paypalHttp(paypalCaptured());
		paypalProvider($http)->completeReturn(paypalView($status), paypalReturn('token=' . PAYPAL_REF));

		expect($http->requests)->toBe([]);
	})->with(['paid', 'expired', 'refunded', 'review']);

	it('captures nothing for an order that never got as far as PayPal', function (): void {
		$http = paypalHttp(paypalCaptured());
		paypalProvider($http)->completeReturn(paypalView('pending', ''), paypalReturn('token='));

		expect($http->requests)->toBe([]);
	});

	it('lets a refusal out, for the store to log: the order is not changed here either way', function (): void {
		$http = paypalHttp(paypalAnswer(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422));

		expect(fn () => paypalProvider($http)->completeReturn(paypalView(), paypalReturn('token=' . PAYPAL_REF)))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('INSTRUMENT_DECLINED'));
	});
});

describe('asking PayPal where a payment stands', function (): void {
	it('reads a captured order as a paid event', function (): void {
		$http  = paypalHttp(paypalCaptured());
		$event = paypalProvider($http)->fetchPayment(PAYPAL_REF);

		expect($http->requests[1]['method'])->toBe('GET')
			->and($http->requests[1]['url'])->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_REF)
			->and($event?->type)->toBe(PaymentEventType::Paid)
			->and($event?->provider)->toBe('paypal')
			->and($event?->orderId)->toBe(PAYPAL_ORDER_ID)
			->and($event?->amount->amount())->toBe(3998)
			->and($event?->amount->currency())->toBe('USD')
			->and($event?->providerRef)->toBe(PAYPAL_REF)
			->and($event?->providerPaymentRef)->toBe(PAYPAL_CAPTURE)
			// The same key as the webhook's event for that capture, so the two are one event.
			->and($event?->eventId)->toBe('capture-' . PAYPAL_CAPTURE);
	});

	it('captures an order the buyer approved and never came back from, then reports it paid', function (): void {
		$http  = paypalHttp(paypalAnswer(['id' => PAYPAL_REF, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => PAYPAL_ORDER_ID]]]), paypalCaptured());
		$event = paypalProvider($http)->fetchPayment(PAYPAL_REF);

		expect($http->requests[2]['url'])->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_REF . '/capture')
			->and(paypalHeaders($http, 2))->toContain('PayPal-Request-Id: capture-' . PAYPAL_ORDER_ID)
			->and($event?->type)->toBe(PaymentEventType::Paid)
			->and($event?->providerPaymentRef)->toBe(PAYPAL_CAPTURE);
	});

	it('has nothing to report for an order nobody has paid, or a capture that is not finished', function (HttpResponse ...$answers): void {
		expect(paypalProvider(paypalHttp(...$answers))->fetchPayment(PAYPAL_REF))->toBeNull();
	})->with([
		'waiting for the buyer'             => [paypalAnswer(['id' => PAYPAL_REF, 'status' => 'PAYER_ACTION_REQUIRED'])],
		'a pending capture'                 => [paypalCaptured('PENDING')],
		'approved, and the capture refused' => [paypalAnswer(['id' => PAYPAL_REF, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => PAYPAL_ORDER_ID]]]), paypalAnswer(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422)],
	]);

	it('reads an order PayPal no longer has as one that ran out', function (): void {
		$http  = paypalHttp(paypalAnswer(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404));
		$event = paypalProvider($http)->fetchPayment(PAYPAL_REF);

		expect($event?->type)->toBe(PaymentEventType::Expired)
			->and($event?->eventId)->toBe('order-' . PAYPAL_REF . '-expired')
			->and($event?->providerRef)->toBe(PAYPAL_REF);
	});

	it('is nothing when PayPal cannot be asked, and asks nothing without an order', function (): void {
		$none = new RecordingHttpClient();

		expect(paypalProvider(new RecordingHttpClient())->fetchPayment(PAYPAL_REF))->toBeNull()
			->and(paypalProvider($none)->fetchPayment(''))->toBeNull()
			->and($none->requests)->toBe([]);
	});
});

describe('refunds', function (): void {
	it('asks PayPal to refund the capture, once', function (): void {
		$http   = paypalHttp(paypalAnswer(['id' => '1Y107995YT783435V', 'status' => 'COMPLETED'], 201));
		$result = paypalProvider($http)->refund(new RefundRequest(PAYPAL_ORDER_ID, PAYPAL_CAPTURE, Money::of(1000, 'USD'), 'Arrived damaged', 'refund-abc'));

		expect($http->requests[1]['url'])->toBe('https://api-m.sandbox.paypal.com/v2/payments/captures/' . PAYPAL_CAPTURE . '/refund')
			->and(paypalBody($http))->toBe(['amount' => ['value' => '10.00', 'currency_code' => 'USD'], 'custom_id' => PAYPAL_ORDER_ID, 'note_to_payer' => 'Arrived damaged'])
			->and(paypalHeaders($http))->toContain('PayPal-Request-Id: refund-abc')
			->and($result->accepted)->toBeTrue()
			->and($result->providerRefundRef)->toBe('1Y107995YT783435V');
	});

	it('leaves out a note when no reason was given, and cuts a long one', function (string $reason, ?int $length): void {
		$http = paypalHttp(paypalAnswer(['id' => 'R1', 'status' => 'COMPLETED'], 201));
		paypalProvider($http)->refund(new RefundRequest(PAYPAL_ORDER_ID, PAYPAL_CAPTURE, Money::of(1000, 'USD'), $reason, 'refund-abc'));
		$note = paypalBody($http)['note_to_payer'] ?? null;

		expect($note === null ? null : mb_strlen($note))->toBe($length);
	})->with([['', null], [str_repeat('x', 400), 255]]);

	it('says it was not accepted when PayPal refuses, without throwing', function (): void {
		$http   = paypalHttp(paypalAnswer(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'CAPTURE_FULLY_REFUNDED']]], 422));
		$result = paypalProvider($http)->refund(new RefundRequest(PAYPAL_ORDER_ID, PAYPAL_CAPTURE, Money::of(1000, 'USD'), '', 'refund-abc'));

		expect($result->accepted)->toBeFalse()
			->and($result->error)->toBe('CAPTURE_FULLY_REFUNDED');
	});
});

describe('reading a webhook', function (): void {
	it('reads each event the store uses', function (string $fixture, PaymentEventType $type, string $orderId, int $amount, string $ref, string $paymentRef, string $eventId): void {
		$event = paypalProvider(new RecordingHttpClient())->parseWebhook(paypalWebhook($fixture));

		expect($event)->toBeInstanceOf(PaymentEvent::class)
			->and($event->provider)->toBe('paypal')
			->and($event->type)->toBe($type)
			->and($event->orderId)->toBe($orderId)
			->and($event->amount->amount())->toBe($amount)
			->and($event->amount->currency())->toBe('USD')
			->and($event->providerRef)->toBe($ref)
			->and($event->providerPaymentRef)->toBe($paymentRef)
			->and($event->eventId)->toBe($eventId);
	})->with([
		// A paid capture is keyed by the capture, so asking PayPal about the order later is the same event.
		'a payment'                    => ['PAYMENT.CAPTURE.COMPLETED', PaymentEventType::Paid, PAYPAL_ORDER_ID, 3998, PAYPAL_REF, PAYPAL_CAPTURE, 'capture-' . PAYPAL_CAPTURE],
		'a failed one'                 => ['PAYMENT.CAPTURE.DENIED', PaymentEventType::Failed, PAYPAL_ORDER_ID, 3998, PAYPAL_REF, PAYPAL_CAPTURE, 'WH-4SW78779LY2325805-07E03580SX1414828'],
		'a refund'                     => ['PAYMENT.CAPTURE.REFUNDED', PaymentEventType::Refunded, PAYPAL_ORDER_ID, 1000, '1Y107995YT783435V', PAYPAL_CAPTURE, 'WH-1GE84257G0350133W-6RW800890C634293G'],
		'a dispute'                    => ['CUSTOMER.DISPUTE.CREATED', PaymentEventType::Disputed, PAYPAL_ORDER_ID, 3998, 'PP-D-27803', PAYPAL_CAPTURE, 'WH-4M0448861G563140B-9EX36365822141321'],
		'a dispute won'                => ['CUSTOMER.DISPUTE.RESOLVED.won', PaymentEventType::DisputeWon, PAYPAL_ORDER_ID, 3998, 'PP-D-27803', PAYPAL_CAPTURE, 'WH-RESOLVED-won'],
		'a dispute lost'               => ['CUSTOMER.DISPUTE.RESOLVED.lost', PaymentEventType::DisputeLost, PAYPAL_ORDER_ID, 3998, 'PP-D-27803', PAYPAL_CAPTURE, 'WH-RESOLVED-lost'],
		'a dispute the buyer withdrew' => ['CUSTOMER.DISPUTE.RESOLVED.canceled', PaymentEventType::DisputeWon, PAYPAL_ORDER_ID, 3998, 'PP-D-27803', PAYPAL_CAPTURE, 'WH-RESOLVED-canceled'],
	]);

	it('makes no request while reading those', function (): void {
		$http = new RecordingHttpClient();
		paypalProvider($http)->parseWebhook(paypalWebhook('PAYMENT.CAPTURE.COMPLETED'));

		expect($http->requests)->toBe([]);
	});

	// The buyer approved the payment and closed the window before coming
	// back. PayPal says so, and the store takes the money it was promised.
	it('captures an order PayPal says the buyer approved, and reports nothing yet', function (): void {
		$http  = paypalHttp(paypalCaptured());
		$event = paypalProvider($http)->parseWebhook(paypalWebhook('CHECKOUT.ORDER.APPROVED'));

		expect($event)->toBeNull()
			->and($http->requests[1]['url'])->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_REF . '/capture')
			->and(paypalHeaders($http))->toContain('PayPal-Request-Id: capture-' . PAYPAL_ORDER_ID);
	});

	// PayPal sends this one late, too: the buyer came back, the order is
	// captured, and the event still says "approved".
	it('asks nothing of PayPal when the approved order has been captured since', function (): void {
		$http = new RecordingHttpClient();

		expect(paypalProvider($http)->parseWebhook(paypalWebhook('CHECKOUT.ORDER.APPROVED.captured')))->toBeNull()
			->and($http->requests)->toBe([]);
	});

	it('logs a capture PayPal refuses then, and does not fail the webhook', function (): void {
		$logger = new RecordingLogger();
		$http   = paypalHttp(paypalAnswer(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422));

		expect(paypalProvider($http, logger: $logger)->parseWebhook(paypalWebhook('CHECKOUT.ORDER.APPROVED')))->toBeNull()
			->and($logger->records[0]['context'] ?? null)->toBe(['orderId' => PAYPAL_ORDER_ID, 'code' => 'ORDER_ALREADY_CAPTURED']);
	});

	it('has no use for anything else, or for a body that is not an event', function (string $body): void {
		$factory = new Psr17Factory();
		$request = $factory->createServerRequest('POST', 'https://shop.example/commerce/webhook/paypal')->withBody($factory->createStream($body));

		expect(paypalProvider(new RecordingHttpClient())->parseWebhook($request))->toBeNull();
	})->with([
		'an event the store does not use' => ['{"id":"WH-1","event_type":"PAYMENT.SALE.COMPLETED","resource":{"id":"x"}}'],
		'no resource'                     => ['{"id":"WH-1","event_type":"PAYMENT.CAPTURE.COMPLETED"}'],
		'no id'                           => ['{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{}}'],
		'not JSON'                        => ['<html>'],
		'nothing'                         => [''],
	]);
});

/*
 * PayPal signs a webhook with a certificate. The store does not check the
 * signature itself: it hands PayPal the headers and the body and asks.
 */
describe('the webhook signature', function (): void {
	beforeEach(function (): void {
		$this->headers = [
			'PAYPAL-AUTH-ALGO'         => 'SHA256withRSA',
			'PAYPAL-CERT-URL'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-1d93a270',
			'PAYPAL-TRANSMISSION-ID'   => '69cd13f0-d67a-11e5-baa3-778b53f4ae55',
			'PAYPAL-TRANSMISSION-SIG'  => 'lmI95Jx3Y9nhR5SJWlHVIWpg4AgFk7n9bCHSRxbrd8A9zrhdu2rMyFrmz+Zjh3s3boXB07VXCXUZy/UFzUlnGJn0wDugt7FlSvdKeIJenLRemUxYCPVoEZzg9VFNqOa48gMkvF+XTpxBeUx/kWy6B5cp7GkT2+pOowfRK7OaynuoUpKNlrCUkSmkN8qhFxUsUGJ5tThpXHZMDPI/cpPovcqFDTOBGLEGNl0EPJdNjIQIKNwi7MFa1AzGH5Fe5FHTD/OXLrIuJVZV1cQF5hD0EuJXg3T0y2nKYQFaYuwJ3QOR2JhB9pT4HRAhOi9yBSWuSEeR+gUJbfHPHbh5gk8J0w==',
			'PAYPAL-TRANSMISSION-TIME' => '2026-11-24T04:00:05Z',
		];
		$this->verifier = static fn (RecordingHttpClient $http, string $webhookId = PAYPAL_WEBHOOK): PayPalWebhookVerifier => new PayPalWebhookVerifier(new PayPalClient(static fn (): HttpClientInterface => $http, PAYPAL_CLIENT, PAYPAL_SECRET, false), $webhookId);
	});

	it('asks PayPal, with the headers, the webhook id and the body exactly as it came', function (): void {
		$http    = paypalHttp(paypalAnswer(['verification_status' => 'SUCCESS']));
		$request = paypalWebhook('PAYMENT.CAPTURE.COMPLETED', $this->headers);
		$raw     = (string)$request->getBody();

		expect(($this->verifier)($http)->verify($request))->toBeTrue()
			->and($http->requests[1]['url'])->toBe('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature');

		$sent = (string)$http->requests[1]['options']['body'];
		// The body is put in as the bytes that were signed, not decoded and written again.
		expect($sent)->toEndWith(',"webhook_event":' . $raw . '}');

		$body = (array)json_decode($sent, true);
		unset($body['webhook_event']);
		expect($body)->toBe([
			'auth_algo'         => 'SHA256withRSA',
			'cert_url'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-1d93a270',
			'transmission_id'   => '69cd13f0-d67a-11e5-baa3-778b53f4ae55',
			'transmission_sig'  => $this->headers['PAYPAL-TRANSMISSION-SIG'],
			'transmission_time' => '2026-11-24T04:00:05Z',
			'webhook_id'        => PAYPAL_WEBHOOK,
		]);
	});

	it('refuses when PayPal says the signature is not good, or cannot be asked', function (HttpResponse ...$answers): void {
		expect(($this->verifier)(new RecordingHttpClient(...$answers))->verify(paypalWebhook('PAYMENT.CAPTURE.COMPLETED', $this->headers)))->toBeFalse();
	})->with([
		'a failure'       => [paypalToken(), paypalAnswer(['verification_status' => 'FAILURE'])],
		'a refusal'       => [paypalToken(), paypalAnswer(['name' => 'VALIDATION_ERROR'], 400)],
		'no token'        => [paypalAnswer(['error' => 'invalid_client'], 401)],
		'no answer'       => [],
	]);

	it('refuses, without asking PayPal, a request that could not be a webhook of PayPal\'s', function (Closure $change): void {
		$http              = paypalHttp(paypalAnswer(['verification_status' => 'SUCCESS']));
		[$request, $hook]  = $change(paypalWebhook('PAYMENT.CAPTURE.COMPLETED', $this->headers));

		expect(($this->verifier)($http, $hook)->verify($request))->toBeFalse()
			->and($http->requests)->toBe([]);
	})->with([
		'no webhook id is set'  => [static fn (ServerRequestInterface $r): array => [$r, '']],
		'a header is missing'   => [static fn (ServerRequestInterface $r): array => [$r->withoutHeader('PAYPAL-TRANSMISSION-SIG'), PAYPAL_WEBHOOK]],
		// The certificate must be PayPal's: an address of anyone else's is never passed on.
		'a certificate from elsewhere' => [static fn (ServerRequestInterface $r): array => [$r->withHeader('PAYPAL-CERT-URL', 'https://evil.example/cert.pem'), PAYPAL_WEBHOOK]],
		'a body that is not JSON'      => [static fn (ServerRequestInterface $r): array => [$r->withBody((new Psr17Factory())->createStream('not json')), PAYPAL_WEBHOOK]],
	]);
});

describe('being ready', function (): void {
	it('needs the client id, the secret and the webhook id', function (string $clientId, string $secret, string $webhookId, bool $ready): void {
		expect(paypalProvider(new RecordingHttpClient(), $clientId, $secret, $webhookId)->isConfigured())->toBe($ready);
	})->with([
		'all three'     => [PAYPAL_CLIENT, PAYPAL_SECRET, PAYPAL_WEBHOOK, true],
		'no webhook id' => [PAYPAL_CLIENT, PAYPAL_SECRET, '', false],
		'no secret'     => [PAYPAL_CLIENT, '', PAYPAL_WEBHOOK, false],
		'no client id'  => ['', PAYPAL_SECRET, PAYPAL_WEBHOOK, false],
	]);

	it('says what it is and what it can do', function (): void {
		$provider     = paypalProvider(new RecordingHttpClient());
		$capabilities = $provider->capabilities();

		expect($provider->id())->toBe('paypal')
			->and($provider->label())->toBe('PayPal')
			->and($capabilities->refunds)->toBeTrue()
			->and($capabilities->partialRefunds)->toBeTrue()
			->and($capabilities->hosted)->toBeTrue()
			->and($capabilities->embedded)->toBeFalse()
			->and($capabilities->subscriptions)->toBeFalse()
			->and($capabilities->recurringCustomAmounts)->toBeFalse()
			->and($capabilities->tax)->toBeFalse()
			->and($provider)->not->toBeInstanceOf(SubscriptionProvider::class)
			->and($provider)->not->toBeInstanceOf(EmbeddedCheckoutProvider::class);
	});

	it('takes the currencies PayPal does, as PayPal writes them', function (string $currency, bool $taken): void {
		expect(paypalProvider(new RecordingHttpClient())->supportsCurrency($currency))->toBe($taken);
	})->with([['USD', true], ['EUR', true], ['JPY', true], ['usd', true], ['HUF', false], ['XXX', false], ['', false]]);
});

describe('the extension', function (): void {
	beforeEach(function (): void {
		/** @param array<string,mixed> $settings the extension's own settings */
		$this->provider = function (array $settings): PayPalProvider {
			$storage = $this->createMock(StorageFilesystemAdapter::class);
			$storage->method('fileExists')->willReturn($settings !== []);
			$storage->method('read')->willReturn((string)json_encode($settings));

			$context = new ExtensionContext(
				ExtensionManifest::fromArray(['id' => 'totalcms/paypal', 'name' => 'PayPal', 'version' => '1.0.0']),
				dirname(__DIR__, 4) . '/resources/extensions/totalcms/paypal',
				$this->createMock(ContainerInterface::class),
				new ExtensionSettingsManager($storage),
				new NullLogger(),
			);
			(new Extension())->register($context);

			return $context->getRegisteredPaymentProviders()[0];
		};
	});

	it('registers the PayPal provider, ready when the mode in use has its three values', function (array $settings, bool $ready): void {
		expect(($this->provider)($settings)->isConfigured())->toBe($ready);
	})->with([
		'nothing set'                    => [[], false],
		'sandbox values, sandbox mode'   => [['sandboxClientId' => PAYPAL_CLIENT, 'sandboxClientSecret' => PAYPAL_SECRET, 'sandboxWebhookId' => PAYPAL_WEBHOOK], true],
		'only live values, sandbox mode' => [['liveClientId' => PAYPAL_CLIENT, 'liveClientSecret' => PAYPAL_SECRET, 'liveWebhookId' => PAYPAL_WEBHOOK], false],
		'live values, live mode'         => [['mode' => 'live', 'liveClientId' => PAYPAL_CLIENT, 'liveClientSecret' => PAYPAL_SECRET, 'liveWebhookId' => PAYPAL_WEBHOOK], true],
		'only sandbox values, live mode' => [['mode' => 'live', 'sandboxClientId' => PAYPAL_CLIENT, 'sandboxClientSecret' => PAYPAL_SECRET, 'sandboxWebhookId' => PAYPAL_WEBHOOK], false],
	]);

	it('ships a manifest that needs Pro and the Commerce add-on, and keeps its secrets secret', function (): void {
		$dir      = dirname(__DIR__, 4) . '/resources/extensions/totalcms/paypal';
		$manifest = json_decode((string)file_get_contents($dir . '/extension.json'), true);
		$settings = json_decode((string)file_get_contents($dir . '/settings.json'), true)['properties'];

		expect($manifest['id'])->toBe('totalcms/paypal')
			->and($manifest['min_edition'])->toBe('pro')
			->and($manifest['entitlement'])->toBe('totalcms/commerce')
			->and(is_file($dir . '/' . $manifest['icon']))->toBeTrue()
			->and(array_keys($settings))->toBe(['mode', 'sandboxClientId', 'sandboxClientSecret', 'sandboxWebhookId', 'liveClientId', 'liveClientSecret', 'liveWebhookId'])
			->and($settings['sandboxClientSecret']['field'])->toBe('secret')
			->and($settings['liveClientSecret']['field'])->toBe('secret');
	});
});
