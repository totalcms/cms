<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Tests\Fakes\OfflineHttpClient;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Service\CheckoutService;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * The bundled Stripe extension in a real site: enabled, given test keys,
 * and switched on in the store. Nothing here reaches Stripe: the test HTTP
 * client fails every request the way a dead network does.
 */

const STRIPE_TEST_WEBHOOK_SECRET = 'whsec_test_secret';

/** Boot a store with the Stripe extension enabled and these settings. */
function bootStoreWithStripe(array $settings, bool $permitted = true, bool $open = true): void
{
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore($open, function () use ($settings, $permitted): void {
		$system = cmsDataDir() . '.system';
		is_dir($system . '/extension-settings/totalcms') || mkdir($system . '/extension-settings/totalcms', 0755, true);
		file_put_contents($system . '/extensions.json', json_encode(['totalcms/stripe' => ['enabled' => true, 'permissions' => ['commerce:payments' => $permitted]]]));
		file_put_contents($system . '/extension-settings/totalcms/stripe.json', json_encode($settings));
	});

	$c                = test()->commerceApp->getContainer();
	$config           = $c->get(Config::class);
	$config->commerce = ['providers' => ['stripe']] + $config->commerce;
	$c->get(ActorContext::class)->set(Actor::anonymous());
	OfflineHttpClient::$attempted = [];
}

/** POST a webhook fixture for $orderId, signed with $secret as Stripe signs. */
function stripeWebhookPost(string $orderId, string $secret = STRIPE_TEST_WEBHOOK_SECRET, string $fixture = 'checkout.session.completed', int $amount = 1250)
{
	$event                                         = json_decode((string)file_get_contents(dirname(__DIR__, 2) . "/fixtures/stripe/{$fixture}.json"), true);
	$event['data']['object']['client_reference_id'] = $orderId;
	$event['data']['object']['amount_total']        = $amount;
	$body                                          = (string)json_encode($event);
	$time                                          = time();

	// The exact bytes are what is signed, so the request is built by hand.
	$request = (new Psr17Factory())->createServerRequest('POST', '/commerce/webhook/stripe')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Stripe-Signature', "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$body}", $secret));
	$request->getBody()->write($body);
	$request->getBody()->rewind();

	return test()->commerceApp->handle($request);
}

const STRIPE_TEST_SETTINGS = ['mode' => 'test', 'testSecretKey' => 'sk_test_51AbCdEfGhIjKlMnOp', 'testWebhookSecret' => STRIPE_TEST_WEBHOOK_SECRET];

it('offers Stripe at checkout once it is enabled, has its keys and is switched on', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS);
	$registry = $this->app->getContainer()->get(PaymentProviderRegistry::class);

	expect($registry->get('stripe')?->isConfigured())->toBeTrue()
		->and(array_map(fn ($p): string => $p->id(), $registry->enabled()))->toBe(['stripe'])
		->and(renderTwig('{% for p in cms.commerce.providers() %}{{ p.id }}:{{ p.label }}{% endfor %}'))->toBe('stripe:Stripe');
});

it('does not offer it without keys, though the store can still see it is installed', function (): void {
	bootStoreWithStripe([]);
	$registry = $this->app->getContainer()->get(PaymentProviderRegistry::class);

	expect($registry->get('stripe'))->not->toBeNull()
		->and($registry->enabled())->toBe([])
		->and(renderTwig('{{ cms.commerce.enabled() ? "open" : "closed" }}'))->toBe('closed');
});

it('is not there at all when an admin has switched its payments capability off', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS, permitted: false);

	expect($this->app->getContainer()->get(PaymentProviderRegistry::class)->get('stripe'))->toBeNull();
});

it('pays an order on a webhook Stripe signed', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS);
	$orders = $this->app->getContainer()->get(OrderRepository::class);
	$order  = $orders->create(commerceDraft(), 'stripe');

	$res  = stripeWebhookPost($order->id());
	$paid = $orders->find($order->id());

	expect($res->getStatusCode())->toBe(200)
		->and($paid->paymentStatus())->toBe('paid')
		->and($paid->providerPaymentRef())->toBe('pi_3A1');
});

it('refuses a webhook signed with anything but the endpoint\'s secret', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS);
	$orders = $this->app->getContainer()->get(OrderRepository::class);
	$order  = $orders->create(commerceDraft(), 'stripe');

	expect(stripeWebhookPost($order->id(), 'whsec_someone_else')->getStatusCode())->toBe(400)
		->and($orders->find($order->id())->paymentStatus())->toBe('pending');
});

it('sends a payment of the wrong amount to review', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS);
	$orders = $this->app->getContainer()->get(OrderRepository::class);
	$order  = $orders->create(commerceDraft(), 'stripe');

	stripeWebhookPost($order->id(), amount: 1);

	expect($orders->find($order->id())->paymentStatus())->toBe('review')
		->and($orders->find($order->id())->needsAttention())->toBe('amount_mismatch');
});

it('still hears Stripe on a site whose Commerce add-on has lapsed', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS, open: false);
	$c      = $this->app->getContainer();
	$orders = $c->get(OrderRepository::class);
	$c->get(\TotalCMS\Domain\Commerce\Service\CommerceInstaller::class)->install();
	$order  = $c->get(ActorContext::class)->runAs(Actor::system(), fn () => $orders->create(new \TotalCMS\Domain\Commerce\Order\OrderDraft('USD', 'buyer@example.com', [], new \TotalCMS\Domain\Commerce\Order\Totals(1250, 0, 0, 0, 1250, [], null, [], 'none'), null, null, []), 'stripe'));

	expect($c->get(PaymentProviderRegistry::class)->enabled())->toBe([])
		->and(stripeWebhookPost($order->id())->getStatusCode())->toBe(200)
		->and($orders->find($order->id())->paymentStatus())->toBe('paid');
});

it('cancels the order and tells the buyer to try again when Stripe cannot be reached', function (): void {
	bootStoreWithStripe(STRIPE_TEST_SETTINGS);
	$c = $this->app->getContainer();
	withHeader('X-CSRF-Token', $c->get(CSRFTokenManager::class)->getToken());
	post('/commerce/cart/add', ['productId' => 'mug'], ['Accept' => 'application/json']);

	$res = post('/commerce/checkout', ['provider' => 'stripe', 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], ['Accept' => 'application/json']);

	expect($res->getStatusCode())->toBe(502)
		->and(json_decode((string)$res->getBody(), true)['error']['code'])->toBe('provider_error')
		->and((string)$res->getBody())->not->toContain('sk_test')
		->and(OfflineHttpClient::$attempted)->toHaveCount(1)
		->and(OfflineHttpClient::$attempted[0])->toContain('https://api.stripe.com/v1/checkout/sessions');
});

describe('on a store that takes payment on its own page', function (): void {
	beforeEach(function (): void {
		$this->boot = function (array $settings): void {
			bootStoreWithStripe($settings);
			$this->c          = $this->app->getContainer();
			$config           = $this->c->get(Config::class);
			$config->commerce = ['checkoutMode' => 'embedded'] + $config->commerce;
		};
	});

	it('is still offered without the publishable key, and takes payment on Stripe\'s own page until it has one', function (array $extra, string $mode): void {
		($this->boot)(STRIPE_TEST_SETTINGS + $extra);
		$offered = $this->c->get(PaymentProviderRegistry::class)->enabled();

		expect($offered)->toHaveCount(1)
			->and($this->c->get(CheckoutService::class)->uiModeFor($offered[0]))->toBe($mode);
	})->with([
		'no publishable key'      => [[], 'hosted'],
		'a live key in test mode' => [['testPublishableKey' => 'pk_live_51AbCdEfGhIjKlMnOp'], 'hosted'],
		'with the key'            => [['testPublishableKey' => 'pk_test_51AbCdEfGhIjKlMnOp'], 'embedded'],
	]);

	it('mounts Stripe\'s form on the pay page from its own module, with no secret of the store\'s in the page', function (): void {
		($this->boot)(STRIPE_TEST_SETTINGS + ['testPublishableKey' => 'pk_test_51AbCdEfGhIjKlMnOp']);
		$orders = $this->c->get(OrderRepository::class);
		$order  = $orders->create(commerceDraft(), 'stripe');
		// As checkout leaves it, once Stripe has answered with a session.
		$order = $orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['providerSessionRef' => 'cs_test_e1', 'providerClientSecret' => 'cs_test_e1_secret_xyz']));

		$res  = get("/commerce/pay/{$order->id()}?t=" . rawurlencode($this->c->get(OrderAccess::class)->token($order)));
		$html = html_entity_decode((string)$res->getBody());
		preg_match('/data-module="([^"]+)"/', $html, $module);

		expect($res->getStatusCode())->toBe(200)
			->and($this->c->get(PaymentProviderRegistry::class)->enabled())->toHaveCount(1)
			->and($module[1] ?? '')->toStartWith('/api/ext/totalcms/stripe/assets/embed.js?v=')
			->and($html)->toContain('"publishableKey":"pk_test_51AbCdEfGhIjKlMnOp"', '"clientSecret":"cs_test_e1_secret_xyz"', '"scriptUrl":"https:\/\/js.stripe.com\/endive\/stripe.js"')
			->and($html)->not->toContain('sk_test_')
			->and($html)->not->toContain('whsec_');

		$asset = get(strtok($module[1], '?'));

		expect($asset->getStatusCode())->toBe(200)
			->and($asset->getHeaderLine('Content-Type'))->toContain('javascript')
			->and((string)$asset->getBody())->toContain('export async function mount(');
	});
});
