<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\OrderReconciler;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * The bundled PayPal extension in a real site: enabled, given sandbox
 * values, and switched on in the store. PayPal itself is a stand-in that
 * records what it was asked and answers what the test queued.
 *
 * A PayPal payment has two steps: the buyer approves it on PayPal's page,
 * then the store captures it. The order is paid when PayPal's webhook says
 * the capture went through, and not before.
 */

const PAYPAL_TEST_SETTINGS = ['mode' => 'sandbox', 'sandboxClientId' => 'AbCdEf-client', 'sandboxClientSecret' => 'EJ-secret-value', 'sandboxWebhookId' => '8PT597110X687430L'];
const PAYPAL_TEST_REF      = '3P199480AE683730M';
const PAYPAL_TEST_CAPTURE  = '8RU61172JS455403V';

/** Boot a store with the PayPal extension enabled, these settings, and a PayPal that answers $http's queue. */
function bootStoreWithPayPal(array $settings = PAYPAL_TEST_SETTINGS, bool $permitted = true): RecordingHttpClient
{
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$http = new RecordingHttpClient();
	bootCommerceStore(true, function () use ($settings, $permitted): void {
		$system = cmsDataDir() . '.system';
		is_dir($system . '/extension-settings/totalcms') || mkdir($system . '/extension-settings/totalcms', 0755, true);
		file_put_contents($system . '/extensions.json', json_encode(['totalcms/paypal' => ['enabled' => true, 'permissions' => ['commerce:payments' => $permitted]]]));
		file_put_contents($system . '/extension-settings/totalcms/paypal.json', json_encode($settings));
	});

	$c = test()->commerceApp->getContainer();
	// The provider asks for the HTTP client when it calls PayPal, so the one
	// set now is the one it gets. Only this test's container has it.
	$c->set(HttpClientInterface::class, $http);
	$config           = $c->get(Config::class);
	$config->commerce = ['providers' => ['paypal']] + $config->commerce;
	$c->get(ActorContext::class)->set(Actor::anonymous());
	withHeader('X-CSRF-Token', $c->get(CSRFTokenManager::class)->getToken());

	return $http;
}

function payPalSays(array $body, int $status = 200): HttpResponse
{
	return new HttpResponse($status, (string)json_encode($body));
}

function payPalTokenAnswer(): HttpResponse
{
	return payPalSays(['access_token' => 'A21AAtoken', 'token_type' => 'Bearer']);
}

function payPalOrderCreated(): HttpResponse
{
	return payPalSays(['id' => PAYPAL_TEST_REF, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . PAYPAL_TEST_REF]]]);
}

function payPalOrderCaptured(string $orderId, string $value = '12.50'): HttpResponse
{
	return payPalSays(['id' => PAYPAL_TEST_REF, 'status' => 'COMPLETED', 'purchase_units' => [['custom_id' => $orderId, 'payments' => ['captures' => [[
		'id' => PAYPAL_TEST_CAPTURE, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => $value], 'custom_id' => $orderId,
	]]]]]]);
}

/** Put a mug in the cart and check out with PayPal. */
function payPalBuysAMug(RecordingHttpClient $http): OrderData
{
	$http->push(payPalTokenAnswer());
	$http->push(payPalOrderCreated());
	post('/commerce/cart/add', ['productId' => 'mug'], ['Accept' => 'application/json']);
	$res = post('/commerce/checkout', ['provider' => 'paypal', 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']]);
	expect($res->getStatusCode())->toBe(303)
		->and($res->getHeaderLine('Location'))->toBe('https://www.sandbox.paypal.com/checkoutnow?token=' . PAYPAL_TEST_REF);

	$c    = test()->commerceApp->getContainer();
	$rows = array_values($c->get(IndexReader::class)->fetchIndex('orders')->objects->all());

	return $c->get(OrderRepository::class)->find((string)$rows[0]['id']);
}

/** PayPal's webhook for a completed capture of $order, with the headers it signs with. */
function payPalWebhookPost(OrderData $order, string $value = '12.50')
{
	$event                                = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/paypal/PAYMENT.CAPTURE.COMPLETED.json'), true);
	$event['resource']['custom_id']       = $order->id();
	$event['resource']['amount']['value'] = $value;

	$request = (new Psr17Factory())->createServerRequest('POST', '/commerce/webhook/paypal')
		->withHeader('Content-Type', 'application/json')
		->withHeader('PAYPAL-AUTH-ALGO', 'SHA256withRSA')
		->withHeader('PAYPAL-CERT-URL', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1')
		->withHeader('PAYPAL-TRANSMISSION-ID', 'tid-1')
		->withHeader('PAYPAL-TRANSMISSION-SIG', 'c2lnbmF0dXJl')
		->withHeader('PAYPAL-TRANSMISSION-TIME', '2026-11-24T04:00:05Z');
	$request->getBody()->write((string)json_encode($event));
	$request->getBody()->rewind();

	return test()->commerceApp->handle($request);
}

it('offers PayPal at checkout once it is enabled, has its values and is switched on', function (): void {
	bootStoreWithPayPal();
	$registry = $this->app->getContainer()->get(PaymentProviderRegistry::class);

	expect($registry->get('paypal')?->isConfigured())->toBeTrue()
		->and(array_map(fn ($p): string => $p->id(), $registry->enabled()))->toBe(['paypal'])
		->and(renderTwig('{% for p in cms.commerce.providers() %}{{ p.id }}:{{ p.label }}{% endfor %}'))->toBe('paypal:PayPal');
});

it('does not offer it without its values, and never for a subscription', function (): void {
	bootStoreWithPayPal([]);
	$registry = $this->app->getContainer()->get(PaymentProviderRegistry::class);
	expect($registry->get('paypal'))->not->toBeNull()
		->and($registry->enabled())->toBe([]);

	bootStoreWithPayPal();
	expect($this->app->getContainer()->get(PaymentProviderRegistry::class)->forCart(true))->toBe([]);
});

it('is not there at all when an admin has switched its payments capability off', function (): void {
	bootStoreWithPayPal(permitted: false);

	expect($this->app->getContainer()->get(PaymentProviderRegistry::class)->get('paypal'))->toBeNull();
});

it('sends the buyer to PayPal to approve the order\'s total', function (): void {
	$http  = bootStoreWithPayPal();
	$order = payPalBuysAMug($http);
	$sent  = json_decode((string)$http->requests[1]['options']['body'], true);

	expect($order->paymentStatus())->toBe('pending')
		->and($order->provider())->toBe('paypal')
		->and($order->providerSessionRef())->toBe(PAYPAL_TEST_REF)
		->and($sent['purchase_units'][0]['custom_id'])->toBe($order->id())
		->and($sent['purchase_units'][0]['amount']['value'])->toBe('12.50')
		->and($sent['payment_source']['paypal']['experience_context']['return_url'])->toContain("/commerce/return/{$order->id()}?t=");
});

it('captures the payment when the buyer comes back, and leaves the order for the webhook to pay', function (): void {
	$http  = bootStoreWithPayPal();
	$order = payPalBuysAMug($http);
	$http->push(payPalOrderCaptured($order->id()));
	$token = rawurlencode($this->app->getContainer()->get(OrderAccess::class)->token($order));

	$res = get("/commerce/return/{$order->id()}?t={$token}&token=" . PAYPAL_TEST_REF . '&PayerID=BUYER1');

	expect($res->getStatusCode())->toBe(200)
		->and(end($http->requests)['url'])->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/' . PAYPAL_TEST_REF . '/capture')
		// Captured at PayPal, and still the webhook's to confirm.
		->and($this->app->getContainer()->get(OrderRepository::class)->find($order->id())->paymentStatus())->toBe('pending');
});

it('shows the buyer their order even when PayPal refuses the capture', function (): void {
	$http  = bootStoreWithPayPal();
	$order = payPalBuysAMug($http);
	$http->push(payPalSays(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422));
	$token = rawurlencode($this->app->getContainer()->get(OrderAccess::class)->token($order));

	expect(get("/commerce/return/{$order->id()}?t={$token}&token=" . PAYPAL_TEST_REF)->getStatusCode())->toBe(200)
		->and($this->app->getContainer()->get(OrderRepository::class)->find($order->id())->paymentStatus())->toBe('pending');
});

it('pays the order on a webhook PayPal says it signed', function (): void {
	$http  = bootStoreWithPayPal();
	$order = payPalBuysAMug($http);
	$http->push(payPalSays(['verification_status' => 'SUCCESS']));

	$res  = payPalWebhookPost($order);
	$paid = $this->app->getContainer()->get(OrderRepository::class)->find($order->id());

	expect($res->getStatusCode())->toBe(200)
		->and(end($http->requests)['url'])->toBe('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature')
		->and($paid->paymentStatus())->toBe('paid')
		// What a refund is later asked of.
		->and($paid->providerPaymentRef())->toBe(PAYPAL_TEST_CAPTURE);
});

it('refuses a webhook PayPal does not vouch for', function (): void {
	$http  = bootStoreWithPayPal();
	$order = payPalBuysAMug($http);
	$http->push(payPalSays(['verification_status' => 'FAILURE']));

	expect(payPalWebhookPost($order)->getStatusCode())->toBe(400)
		->and($this->app->getContainer()->get(OrderRepository::class)->find($order->id())->paymentStatus())->toBe('pending');
});

it('sends a payment of the wrong amount to review', function (): void {
	$http  = bootStoreWithPayPal();
	$order = payPalBuysAMug($http);
	$http->push(payPalSays(['verification_status' => 'SUCCESS']));

	payPalWebhookPost($order, '1.00');

	expect($this->app->getContainer()->get(OrderRepository::class)->find($order->id())->needsAttention())->toBe('amount_mismatch');
});

// The webhook never came. Reconcile asks PayPal, and the webhook arriving
// late after all is the same payment, counted once.
it('is paid by reconcile when the webhook never came, and the late webhook changes nothing', function (): void {
	$http   = bootStoreWithPayPal();
	$order  = payPalBuysAMug($http);
	$c      = $this->app->getContainer();
	$orders = $c->get(OrderRepository::class);
	$http->push(payPalOrderCaptured($order->id()));

	$report = $c->get(OrderReconciler::class)->run(0);
	$paidAt = $orders->find($order->id())->paidAt();

	expect($report[0]['result'])->toBe('paid')
		->and($paidAt)->not->toBe('');

	$http->push(payPalSays(['verification_status' => 'SUCCESS']));
	expect(payPalWebhookPost($order)->getStatusCode())->toBe(200)
		->and($orders->find($order->id())->paidAt())->toBe($paidAt)
		->and($orders->find($order->id())->needsAttention())->toBe('');
});

it('cancels the order and tells the buyer to try again when PayPal cannot be reached', function (): void {
	bootStoreWithPayPal();
	post('/commerce/cart/add', ['productId' => 'mug'], ['Accept' => 'application/json']);

	$res = post('/commerce/checkout', ['provider' => 'paypal', 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], ['Accept' => 'application/json']);

	expect($res->getStatusCode())->toBe(502)
		->and(json_decode((string)$res->getBody(), true)['error']['code'])->toBe('provider_error')
		->and((string)$res->getBody())->not->toContain('EJ-secret-value');
});
