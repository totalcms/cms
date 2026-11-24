<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\Stripe\StripeClient;
use TotalCMS\Bundled\Stripe\StripeProvider;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\ProviderEvent;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Commerce\Payment\SubscriptionProvider;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/stripe/Extension.php';

/*
 * Stripe bills the store's subscriptions. Offline, as in StripeProviderTest:
 * every request the provider would send is recorded.
 *
 * What it must make true: the first payment is exactly the order's total,
 * and every period after is exactly the subscription's recurring total. It
 * says so before it calls Stripe, and refuses when it cannot.
 *
 * The tee is $19.99, paid for once. The club is $10.00 a month.
 */

const SUBS_KEY    = 'sk_test_51AbCdEfGhIjKlMnOpQrStUvWxYz';
const SUBS_ORDER  = '0123456789abcdef0123456789abcdef';
const SUBS_ENGINE = 'fedcba9876543210fedcba9876543210';

function subsProvider(RecordingHttpClient $http, ?RecordingLogger $logger = null): StripeProvider
{
	return new StripeProvider(new StripeClient(static fn (): HttpClientInterface => $http, SUBS_KEY), SUBS_KEY, 'whsec_test_secret', '', $logger ?? new NullLogger());
}

function subsUsd(int $amount): Money
{
	return Money::of($amount, 'USD');
}

/** The tee, paid for once. */
function subsTee(int $discount = 0): CheckoutLineSummary
{
	return new CheckoutLineSummary('Tee', 1, subsUsd(1999), subsUsd($discount), subsUsd(0));
}

/** The club, billed every month. */
function subsClub(int $qty = 1, int $discount = 0, int $recurringDiscount = 0, string $interval = 'month', int $every = 1): CheckoutLineSummary
{
	return new CheckoutLineSummary('Club', $qty, subsUsd(1000), subsUsd($discount), subsUsd($recurringDiscount), new Recurrence($interval, $every));
}

/** @param list<CheckoutLineSummary> $lines */
function subsCheckout(array $lines, int $total, int $recurringTotal, array $with = []): CheckoutRequest
{
	return new CheckoutRequest(...$with + [
		'orderId'        => SUBS_ORDER,
		'orderNumber'    => 1001,
		'total'          => subsUsd($total),
		'lines'          => $lines,
		'shipping'       => subsUsd(0),
		'tax'            => subsUsd(0),
		'email'          => 'buyer@example.com',
		'successUrl'     => 'https://shop.example/commerce/return/' . SUBS_ORDER . '?t=abc',
		'cancelUrl'      => 'https://shop.example/commerce/cancel/' . SUBS_ORDER . '?t=abc',
		'returnUrl'      => '',
		'metadata'       => ['orderId' => SUBS_ORDER, 'orderNumber' => '1001'],
		'idempotencyKey' => 'checkout-' . SUBS_ORDER,
		'mode'           => 'subscription',
		'recurringTotal' => subsUsd($recurringTotal),
		'recurringTax'   => subsUsd(0),
		'subscriptionId' => SUBS_ENGINE,
	]);
}

function subsJson(array $body, int $status = 200): HttpResponse
{
	return new HttpResponse($status, (string)json_encode($body));
}

function subsSession(): HttpResponse
{
	return subsJson(['id' => 'cs_test_s1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_s1']);
}

/** The form a recorded request carried, as PHP reads it. */
function subsForm(RecordingHttpClient $http, int $index = 0): array
{
	parse_str((string)($http->requests[$index]['options']['body'] ?? ''), $form);

	return $form;
}

function subsWebhook(string $fixture): ServerRequestInterface
{
	$factory = new Psr17Factory();
	$body    = (string)file_get_contents(dirname(__DIR__, 3) . "/fixtures/stripe/{$fixture}.json");

	return $factory->createServerRequest('POST', 'https://shop.example/commerce/webhook/stripe')->withBody($factory->createStream($body));
}

/** An invoice as Stripe answers it when asked for its payments too. */
function subsInvoice(string $id, string $paymentIntent = 'pi_3Renewal'): HttpResponse
{
	return subsJson(['id' => $id, 'object' => 'invoice', 'total' => 1000, 'payments' => ['object' => 'list', 'data' => [['id' => 'inpay_1', 'status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntent]]]]]);
}

it('says it can bill subscriptions and has a page for the buyer\'s card', function (): void {
	$provider = subsProvider(new RecordingHttpClient());

	expect($provider)->toBeInstanceOf(SubscriptionProvider::class)
		->and($provider->capabilities()->subscriptions)->toBeTrue()
		->and($provider->capabilities()->billingPortal)->toBeTrue();
});

describe('starting a subscription', function (): void {
	it('posts a subscription session whose items match both totals', function (): void {
		$http    = new RecordingHttpClient(subsSession());
		$session = subsProvider($http)->createCheckout(subsCheckout([subsTee(), subsClub()], 2999, 1000));
		$form    = subsForm($http);

		expect($http->requests)->toHaveCount(1)
			->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions')
			->and($session->providerSessionRef)->toBe('cs_test_s1')
			->and($form)->toMatchArray(['mode' => 'subscription', 'client_reference_id' => SUBS_ORDER, 'customer_email' => 'buyer@example.com'])
			->and($form['line_items'])->toHaveCount(2)
			->and($form['line_items'][0]['price_data'])->not->toHaveKey('recurring')
			->and($form['line_items'][0]['price_data']['unit_amount'])->toBe('1999')
			->and($form['line_items'][1]['price_data']['unit_amount'])->toBe('1000')
			->and($form['line_items'][1]['price_data']['recurring'])->toBe(['interval' => 'month', 'interval_count' => '1'])
			->and($form['subscription_data']['metadata'])->toBe(['subscriptionId' => SUBS_ENGINE, 'orderId' => SUBS_ORDER, 'orderNumber' => '1001'])
			// A subscription has no payment of its own to describe: Stripe refuses the field.
			->and($form)->not->toHaveKey('payment_intent_data')
			->and($form)->not->toHaveKey('discounts');
	});

	it('bills a plan every so many intervals, as the store set it', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsClub(1, 0, 0, 'week', 2)], 1000, 1000));

		expect(subsForm($http)['line_items'][0]['price_data']['recurring'])->toBe(['interval' => 'week', 'interval_count' => '2']);
	});

	it('keeps a recurring line\'s quantity when nothing is taken off it', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsClub(3)], 3000, 3000));
		$item = subsForm($http)['line_items'][0];

		expect($item['quantity'])->toBe('3')->and($item['price_data']['unit_amount'])->toBe('1000');
	});

	it('bakes a repeating discount into the recurring item', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsClub(2, 200, 200)], 1800, 1800));
		$form = subsForm($http);

		expect($http->requests)->toHaveCount(1)
			->and($form['line_items'][0]['quantity'])->toBe('1')
			->and($form['line_items'][0]['price_data']['unit_amount'])->toBe('1800')
			->and($form['line_items'][0]['price_data']['product_data']['name'])->toBe('2 × Club')
			->and($form)->not->toHaveKey('discounts');
	});

	it('makes one coupon for a first-payment discount', function (): void {
		$http = new RecordingHttpClient(subsJson(['id' => 'co_1']), subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsTee(), subsClub(1, 100, 0)], 2899, 1000, ['metadata' => ['orderId' => SUBS_ORDER, 'orderNumber' => '1001', 'discountCode' => 'FIRST10']]));
		$coupon = subsForm($http, 0);

		expect($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/coupons')
			->and($coupon)->toMatchArray(['amount_off' => '100', 'currency' => 'usd', 'duration' => 'once', 'max_redemptions' => '1'])
			->and($http->requests[0]['options']['headers'])->toContain('Idempotency-Key: coupon-' . SUBS_ORDER)
			->and($http->requests[1]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions')
			->and(subsForm($http, 1)['discounts'])->toBe([['coupon' => 'co_1']])
			// The plan itself is billed in full every period after.
			->and(subsForm($http, 1)['line_items'][1]['price_data']['unit_amount'])->toBe('1000');
	});

	it('takes a one-time line\'s discount off that line, with no coupon', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsTee(199), subsClub()], 2800, 1000));

		expect($http->requests)->toHaveCount(1)
			->and(subsForm($http)['line_items'][0]['price_data']['unit_amount'])->toBe('1800');
	});

	it('refuses items that add up to less than the total, and asks Stripe for nothing', function (): void {
		$http = new RecordingHttpClient(subsSession());

		expect(fn () => subsProvider($http)->createCheckout(subsCheckout([subsClub()], 1500, 1000)))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('total_mismatch'))
			->and($http->requests)->toBe([]);
	});

	it('refuses recurring items that do not add up to what every period costs', function (): void {
		$http = new RecordingHttpClient(subsSession());

		expect(fn () => subsProvider($http)->createCheckout(subsCheckout([subsClub()], 1000, 900)))
			->toThrow(fn (ProviderException $e) => expect($e->providerCode)->toBe('total_mismatch'))
			->and($http->requests)->toBe([]);
	});

	it('refuses a subscription with nothing in it that recurs', function (): void {
		$http = new RecordingHttpClient(subsSession());

		expect(fn () => subsProvider($http)->createCheckout(subsCheckout([subsTee()], 1999, 0)))
			->toThrow(ProviderException::class)
			->and($http->requests)->toBe([]);
	});

	it('adds the store\'s tax: what is due every period as a recurring item, the rest once', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsTee(), subsClub()], 3299, 1100, ['tax' => subsUsd(300), 'recurringTax' => subsUsd(100)]));
		$items = subsForm($http)['line_items'];

		expect($items)->toHaveCount(4)
			->and($items[2]['price_data'])->toMatchArray(['unit_amount' => '100', 'recurring' => ['interval' => 'month', 'interval_count' => '1']])
			->and($items[2]['price_data']['product_data']['name'])->toBe('Tax')
			->and($items[3]['price_data']['unit_amount'])->toBe('200')
			->and($items[3]['price_data'])->not->toHaveKey('recurring');
	});

	it('charges shipping once, with the first payment', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsTee(), subsClub()], 3499, 1000, ['shipping' => subsUsd(500)]));
		$items = subsForm($http)['line_items'];

		expect($items[2]['price_data']['product_data']['name'])->toBe('Shipping')
			->and($items[2]['price_data'])->not->toHaveKey('recurring');
	});

	it('reuses the buyer\'s Stripe customer', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsClub()], 1000, 1000, ['providerCustomerRef' => 'cus_1']));
		$form = subsForm($http);

		expect($form['customer'])->toBe('cus_1')->and($form)->not->toHaveKey('customer_email');
	});

	it('has Stripe work out the tax on every payment when the store left the tax to it', function (): void {
		$http = new RecordingHttpClient(subsSession());
		subsProvider($http)->createCheckout(subsCheckout([subsTee(), subsClub()], 2999, 1000, ['providerTax' => true]));
		$form = subsForm($http);

		expect($form['automatic_tax'])->toBe(['enabled' => 'true'])
			->and($form['line_items'][1]['price_data']['tax_behavior'])->toBe('exclusive')
			->and($form['line_items'])->toHaveCount(2);
	});

	it('still refuses a kind of checkout it does not know', function (): void {
		expect(fn () => subsProvider(new RecordingHttpClient())->createCheckout(subsCheckout([subsClub()], 1000, 1000, ['mode' => 'setup'])))
			->toThrow(ProviderException::class);
	});
});

describe('what Stripe tells the store', function (): void {
	it('maps each subscription webhook', function (string $fixture, string $class, string $type, array $responses): void {
		$http  = new RecordingHttpClient(...array_map(static fn (string $id): HttpResponse => subsInvoice($id), $responses));
		$event = subsProvider($http)->parseWebhook(subsWebhook($fixture));

		expect($event)->toBeInstanceOf($class)->and($event->type->value)->toBe($type);
	})->with([
		['subscription-session-completed', PaymentEvent::class, 'paid', ['in_1First']],
		['invoice-paid-cycle', SubscriptionEvent::class, 'renewed', ['in_2Cycle']],
		['invoice-payment-failed', SubscriptionEvent::class, 'payment_failed', []],
		['subscription-updated', SubscriptionEvent::class, 'updated', []],
		['subscription-deleted', SubscriptionEvent::class, 'canceled', []],
		['charge-refunded-renewal', PaymentEvent::class, 'refunded', []],
	]);

	it('reads the first payment of a subscription: the provider\'s names for it, and the payment from the invoice', function (): void {
		$http  = new RecordingHttpClient(subsInvoice('in_1First', 'pi_3First'));
		$event = subsProvider($http)->parseWebhook(subsWebhook('subscription-session-completed'));

		expect($event)->toBeInstanceOf(PaymentEvent::class)
			->and($event->orderId)->toBe(SUBS_ORDER)
			->and($event->amount->amount())->toBe(1000)
			->and($event->subscriptionRef)->toBe('sub_1A')
			->and($event->customerRef)->toBe('cus_A1')
			->and($event->providerPaymentRef)->toBe('pi_3First')
			->and($http->requests[0]['method'])->toBe('GET')
			->and($http->requests[0]['url'])->toStartWith('https://api.stripe.com/v1/invoices/in_1First?')
			->and(urldecode($http->requests[0]['url']))->toContain('expand[0]=payments');
	});

	it('asks to be told again when the first payment\'s invoice cannot be read', function (): void {
		expect(fn () => subsProvider(new RecordingHttpClient())->parseWebhook(subsWebhook('subscription-session-completed')))
			->toThrow(ProviderException::class);
	});

	it('reads a period paid: the invoice, the amount, the payment and the store\'s own id', function (): void {
		$http  = new RecordingHttpClient(subsInvoice('in_2Cycle', 'pi_3Renewal'));
		$event = subsProvider($http)->parseWebhook(subsWebhook('invoice-paid-cycle'));

		expect($event)->toBeInstanceOf(SubscriptionEvent::class)
			->and($event->type)->toBe(SubscriptionEventType::Renewed)
			->and($event->eventId)->toBe('evt_1InvoiceCycle')
			->and($event->subscriptionId)->toBe(SUBS_ENGINE)
			->and($event->providerSubscriptionRef)->toBe('sub_1A')
			->and($event->providerInvoiceRef)->toBe('in_2Cycle')
			->and($event->providerPaymentRef)->toBe('pi_3Renewal')
			->and($event->amount?->amount())->toBe(1000)
			->and($event->amount?->currency())->toBe('USD')
			->and($event->currentPeriodEnd)->toBe(1900000000)
			->and($event->tax)->toBeNull()
			->and($event->orderTotal)->toBeNull();
	});

	it('reads the tax Stripe worked out on a period, and what the period cost before it', function (): void {
		$event = subsProvider(new RecordingHttpClient(subsInvoice('in_2Taxed')))->parseWebhook(subsWebhook('invoice-paid-cycle-taxed'));

		expect($event->amount?->amount())->toBe(1060)
			->and($event->tax?->amount())->toBe(60)
			->and($event->orderTotal?->amount())->toBe(1000);
	});

	it('ignores the first invoice of a subscription: the checkout\'s own event covers it', function (): void {
		$http = new RecordingHttpClient();

		expect(subsProvider($http)->parseWebhook(subsWebhook('invoice-paid-create')))->toBeNull()
			->and($http->requests)->toBe([]);
	});

	it('reads a failed charge by its invoice', function (): void {
		$event = subsProvider(new RecordingHttpClient())->parseWebhook(subsWebhook('invoice-payment-failed'));

		expect($event->type)->toBe(SubscriptionEventType::PaymentFailed)
			->and($event->providerInvoiceRef)->toBe('in_3Failed')
			->and($event->subscriptionId)->toBe(SUBS_ENGINE)
			->and($event->providerSubscriptionRef)->toBe('sub_1A');
	});

	it('reads a change: the status, the period\'s end from the item, and "cancel at the end"', function (): void {
		$event = subsProvider(new RecordingHttpClient())->parseWebhook(subsWebhook('subscription-updated'));

		expect($event->type)->toBe(SubscriptionEventType::Updated)
			->and($event->status)->toBe('past_due')
			->and($event->subscriptionId)->toBe(SUBS_ENGINE)
			->and($event->providerSubscriptionRef)->toBe('sub_1A')
			->and($event->currentPeriodEnd)->toBe(1900000000)
			->and($event->cancelAtPeriodEnd)->toBeFalse();
	});

	it('turns Stripe\'s words for a status into the store\'s', function (string $stripe, ?string $store): void {
		$payload                             = (array)json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/stripe/subscription-updated.json'), true);
		$payload['data']['object']['status'] = $stripe;
		$factory                             = new Psr17Factory();
		$request                             = $factory->createServerRequest('POST', 'https://shop.example/commerce/webhook/stripe')->withBody($factory->createStream((string)json_encode($payload)));

		$event = subsProvider(new RecordingHttpClient())->parseWebhook($request);

		expect($event instanceof ProviderEvent ? $event->status : null)->toBe($store);
	})->with([
		['trialing', 'trialing'], ['active', 'active'], ['past_due', 'past_due'], ['paused', 'paused'],
		['canceled', 'canceled'], ['unpaid', 'canceled'], ['incomplete_expired', 'canceled'],
		// Not begun yet: the checkout's own event says when it has.
		['incomplete', null],
	]);

	it('reads a refund of a renewal by its payment: the charge names no order', function (): void {
		$event = subsProvider(new RecordingHttpClient())->parseWebhook(subsWebhook('charge-refunded-renewal'));

		expect($event->orderId)->toBe('')
			->and($event->providerPaymentRef)->toBe('pi_3Renewal')
			->and($event->amount->amount())->toBe(1000);
	});
});

describe('what the store asks of Stripe', function (): void {
	it('cancels at the end of the period with a POST, and at once with a DELETE', function (): void {
		$http     = new RecordingHttpClient(subsJson(['id' => 'sub_1A']), subsJson(['id' => 'sub_1A']));
		$provider = subsProvider($http);

		$provider->cancelSubscription('sub_1A', true);
		$provider->cancelSubscription('sub_1A', false);

		expect($http->methods())->toBe(['POST', 'DELETE'])
			->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/subscriptions/sub_1A')
			->and(subsForm($http, 0))->toBe(['cancel_at_period_end' => 'true'])
			->and($http->requests[1]['url'])->toBe('https://api.stripe.com/v1/subscriptions/sub_1A');
	});

	it('says so when Stripe will not cancel', function (): void {
		$http = new RecordingHttpClient(subsJson(['error' => ['code' => 'resource_missing', 'message' => 'No such subscription']], 404));

		expect(fn () => subsProvider($http)->cancelSubscription('sub_gone', false))->toThrow(ProviderException::class);
	});

	it('asks where a subscription stands', function (): void {
		$subscription = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/stripe/subscription-updated.json'), true)['data']['object'];
		$http         = new RecordingHttpClient(subsJson($subscription));
		$event        = subsProvider($http)->fetchSubscription('sub_1A');

		expect($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/subscriptions/sub_1A')
			->and($event?->type)->toBe(SubscriptionEventType::Updated)
			->and($event?->status)->toBe('past_due')
			->and($event?->subscriptionId)->toBe(SUBS_ENGINE)
			->and(subsProvider(new RecordingHttpClient())->fetchSubscription('sub_1A'))->toBeNull()
			->and(subsProvider(new RecordingHttpClient())->fetchSubscription(''))->toBeNull();
	});

	it('gives the buyer Stripe\'s page for their card, or nothing when Stripe refuses', function (): void {
		$http   = new RecordingHttpClient(subsJson(['id' => 'bps_1', 'url' => 'https://billing.stripe.com/p/session/test_1']));
		$logger = new RecordingLogger();

		$url = subsProvider($http)->billingPortalUrl('cus_A1', 'https://shop.example/commerce/subscription/abc?t=1');

		expect($url)->toBe('https://billing.stripe.com/p/session/test_1')
			->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/billing_portal/sessions')
			->and(subsForm($http))->toBe(['customer' => 'cus_A1', 'return_url' => 'https://shop.example/commerce/subscription/abc?t=1'])
			->and(subsProvider(new RecordingHttpClient(), $logger)->billingPortalUrl('cus_A1', 'https://shop.example/'))->toBe('')
			->and(subsProvider(new RecordingHttpClient())->billingPortalUrl('', 'https://shop.example/'))->toBe('');
	});
});
