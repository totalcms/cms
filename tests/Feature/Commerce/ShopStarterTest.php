<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Tests\Fakes\ClosedCommerceGate;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Builder\Data\StarterManifest;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Builder\Service\StarterService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Commerce\Service\CommerceStarterSetup;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * `tcms builder:init shop`: a working store in one command. Pages for the
 * shop, a product, the cart and checkout, an account, a sign-up form and a
 * members-only page; six products to sell; and a membership that works
 * from the first sale.
 *
 * It installs Commerce first, and on a site without Commerce it says so
 * and leaves the site as it was.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	// The folder a site's own templates live in is found when the site boots.
	// On a real site the starter is installed by one command and its pages are
	// asked for by the next; here both happen in one, so the folder is made first.
	bootCommerceStore(true, static fn () => is_dir(cmsDataDir() . '/builder') || mkdir(cmsDataDir() . '/builder', 0755, true));
	$this->c = $this->app->getContainer();
	// A docroot of the test's own: a starter copies its stylesheet there.
	$this->docroot = dirname(cmsDataDir()) . '/shop-starter-docroot';
	recursiveDelete($this->docroot);
	mkdir($this->docroot, 0755, true);
	$this->c->get(Config::class)->docroot = $this->docroot;

	$this->scaffold = fn () => $this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(StarterService::class)->scaffold('shop', true));
	$this->page     = fn (string $id): array => $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('builder-pages', $id)->toArray();
	$this->product  = fn (string $id): array => $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('products', $id)->toArray();
});

afterEach(fn () => recursiveDelete($this->docroot));

it('is one of the starters, and says it needs Commerce', function (): void {
	$starters = array_filter($this->c->get(StarterService::class)->listStarters(), static fn (StarterManifest $m): bool => $m->name === 'Shop');

	expect($starters)->toHaveCount(1)
		->and(array_values($starters)[0]->requires)->toBe(['commerce']);
});

describe('on a site with Commerce', function (): void {
	beforeEach(function (): void {
		$this->result = ($this->scaffold)();
	});

	it('scaffolds, and imports what it seeds without an error', function (): void {
		expect($this->result->success)->toBeTrue()
			->and($this->result->message)->toContain('jumpstart imported')
			->and(is_file($this->docroot . '/assets/css/style.css'))->toBeTrue();
	});

	it('makes the pages a store needs, each at its address', function (string $id, string $route): void {
		expect(($this->page)($id)['route'])->toBe($route);
	})->with([
		['home', '/'], ['shop', '/shop'], ['product', '/shop/{id}'], ['cart', '/cart'],
		['account', '/account'], ['register', '/register'], ['members', '/members'], ['readme', '/readme'],
	]);

	it('keeps the account for customers who are signed in, and the members page for members', function (): void {
		$account = ($this->page)('account');
		$members = ($this->page)('members');

		expect($account['middleware'])->toBe(['auth'])
			->and($account['accessCollection'])->toBe('customers')
			->and($account['accessGroups'])->toBe([])
			->and($members['middleware'])->toBe(['auth'])
			->and($members['accessCollection'])->toBe('customers')
			->and($members['accessGroups'])->toBe(['members']);
	});

	it('seeds six products a buyer can buy: one with sizes, one download, one membership', function (): void {
		$catalog = $this->c->get(ProductCatalog::class);
		$ids     = ['canvas-tote', 'enamel-mug', 'classic-tee', 'sticker-pack', 'field-guide', 'membership'];
		foreach ($ids as $id) {
			expect(($this->product)($id)['status'])->toBe('active');
		}
		$tee        = ($this->product)('classic-tee');
		$membership = ($this->product)('membership');

		expect(array_keys($tee['variants']))->toBe(['small', 'medium', 'large'])
			->and($catalog->purchasable('classic-tee', 'medium')->id)->toBe('classic-tee')
			->and(($this->product)('field-guide')['type'])->toBe('digital')
			->and($membership['billing'])->toBe('recurring')
			->and($membership['interval'])->toBe('month')
			->and($membership['accessGroup'])->toBe('members')
			->and($membership['requiresShipping'])->toBeFalse();
	});

	it('makes a place for customers to sign up, and the group a membership gives', function (): void {
		$customers = $this->c->get(CollectionFetcher::class)->fetchCollection('customers');
		$group     = $this->c->get(AccessGroupRepository::class)->findById('members');

		expect($customers->schema)->toBe('auth')
			->and($customers->publicRegistration)->toBeTrue()
			->and($group)->not->toBeNull()
			// A member can see pages. They are not staff: no admin, no store.
			->and($group->permissions['commerce'] ?? false)->toBeFalse()
			->and($group->permissions['playground'] ?? false)->toBeFalse()
			->and($group->allowsCollection('read', 'orders'))->toBeFalse()
			->and($group->allowsCollection('read', 'customers'))->toBeFalse();
	});

	it('sets the two store settings a membership needs, where the owner has not chosen', function (): void {
		$commerce = $this->c->get(Config::class)->commerce;

		expect($commerce['customerCollection'])->toBe('customers')
			->and($commerce['grantableGroups'])->toBe(['members']);
	});

	it('shows the shop, a product with its sizes, and the cart', function (): void {
		$shop = (string)get('/shop')->getBody();
		$tee  = (string)get('/shop/classic-tee')->getBody();

		expect($shop)->toContain('Canvas Tote', 'Enamel Mug', 'Classic Tee', 'Membership', 'commerce-add')
			->and($tee)->toContain('Classic Tee', 'Small', 'Medium', 'Large')
			->and(get('/cart')->getStatusCode())->toBe(200)
			->and(get('/')->getStatusCode())->toBe(200)
			->and(get('/readme')->getStatusCode())->toBe(200)
			->and(get('/shop/no-such-product')->getStatusCode())->toBeIn([200, 404]);
	});

	it('has a sign-up form that posts to the customers collection', function (): void {
		$html = html_entity_decode((string)get('/register')->getBody());

		expect($html)->toContain('/admin/register/customers');
	});

	it('sends a visitor who is not signed in away from the account and the members page', function (string $path): void {
		$response = get($path);

		expect($response->getStatusCode())->toBeIn([302, 303])
			->and($response->getHeaderLine('Location'))->toContain('/admin/login/customers');
	})->with(['/account', '/members']);

	it('lets a customer in to the members page once their membership is paid for', function (): void {
		$sub = enableSubscriptions();
		$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectSaver::class)->saveObject('customers', ['id' => 'pat', 'name' => 'Pat', 'email' => 'pat@example.test', 'password' => quickPasswordHash(), 'active' => true]));
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();
		withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());
		signInAs($this->app, 'pat', 'customers');
		$before = get('/members')->getStatusCode();

		postJson('/commerce/cart/add', ['productId' => 'membership', 'variantId' => '', 'qty' => 1]);
		post('/commerce/checkout', ['provider' => 'fake-sub', 'email' => 'pat@example.test'], ['Accept' => 'application/json']);
		$orders = $this->c->get(OrderRepository::class);
		$order  = $orders->find((string)$orders->index()[0]['id']);
		$this->c->get(ProviderEventRouter::class)->route(new PaymentEvent('fake-sub', 'evt_1', PaymentEventType::Paid, $order->id(), Money::of($order->grandTotal(), 'USD'), 'pi_1', 'pi_1', [], 'sub_1', 'cus_1'));

		$members = get('/members');
		$account = html_entity_decode((string)get('/account')->getBody());

		expect($before)->toBe(403)
			->and($sub->checkouts[0]->mode)->toBe('subscription')
			->and($members->getStatusCode())->toBe(200)
			->and((string)$members->getBody())->toContain('members-only')
			->and($account)->toContain((string)$order->number(), 'Membership', 'commerce/subscription/');
	});
});

it('leaves settings the owner has already chosen as they are', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['customerCollection' => 'clients', 'grantableGroups' => ['vip']] + $config->commerce;

	($this->scaffold)();

	expect($config->commerce['customerCollection'])->toBe('clients')
		->and($config->commerce['grantableGroups'])->toBe(['vip']);
});

it('says why, and scaffolds nothing, on a site without Commerce', function (): void {
	$this->c->set(CommerceGate::class, new ClosedCommerceGate());
	$this->c->set(CommerceStarterSetup::class, new CommerceStarterSetup(
		$this->c->get(CommerceGate::class),
		$this->c->get(CommerceInstaller::class),
		$this->c->get(SettingsSaver::class),
		$this->c->get(Config::class),
	));
	$service = new StarterService(
		$this->c->get(BuilderConfigService::class),
		$this->c->get(BuilderInstaller::class),
		$this->c->get(TemplateLister::class),
		$this->c->get(TemplateMigrationService::class),
		$this->c->get(JumpStartImporter::class),
		$this->c->get(BuilderOrderService::class),
		$this->c->get(CommerceStarterSetup::class),
		$this->c->get(LoggerFactory::class),
	);

	$result = $service->scaffold('shop', true);

	expect($result->success)->toBeFalse()
		->and($result->message)->toContain('Commerce')
		->and(is_file($this->docroot . '/assets/css/style.css'))->toBeFalse()
		->and($this->c->get(ObjectFetcher::class)->existsObject('builder-pages', 'shop'))->toBeFalse();
});

it('keeps its stylesheet and its templates free of anything that would break a page', function (): void {
	$dir   = PathResolver::packageRoot() . '/resources/builder/starters/shop';
	$files = array_merge(glob("{$dir}/pages/{,*/}*.twig", GLOB_BRACE) ?: [], glob("{$dir}/{layouts,partials}/*.twig", GLOB_BRACE) ?: []);

	expect($files)->not->toBeEmpty();
	foreach ($files as $file) {
		// Autoescape is off in this app: a starter is what people copy from.
		expect((string)file_get_contents($file))->not->toMatch('/\{\{\s*(product|row|order|line|subscription)\.(title|email|category|sku)\s*\}\}/');
	}
});
