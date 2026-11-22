<?php

declare(strict_types=1);

use TotalCMS\Domain\Activity\Data\ActivityFilter;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Commerce\Service\StoreRecords;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\postJson;

/*
 * The route guard keeps the collection API away from orders, transactions,
 * carts and subscriptions. These tests hold the same line everywhere else an
 * object can be written: the storage layer refuses every actor but the site
 * itself, so an MCP tool, an import, the factory, a clone or a restore cannot
 * make, change or delete one. They also hold that the store's own writes are
 * not reported as content changes.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c      = $this->app->getContainer();
	$this->actors = $this->c->get(ActorContext::class);

	$this->c->get(CommerceInstaller::class)->install();
	$this->asSystem = fn (Closure $fn): mixed => $this->actors->runAs(Actor::system(), $fn);
	($this->asSystem)(function (): void {
		$this->c->get(ObjectSaver::class)->saveObject('orders', ['id' => 'order-one', 'number' => 1001, 'grandTotal' => 1999]);
		$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'mug', 'title' => 'Mug', 'status' => 'active', 'price' => 12.5]);
	});

	$this->admin  = new Actor(ActorKind::Admin, 'admin-user-test-com', 'auth');
	$this->exists = fn (string $collection, string $id): bool => $this->c->get(ObjectFetcher::class)->existsObject($collection, $id);
});

describe('which collections hold store records', function (): void {
	test('the four the store writes, by their schema', function (string $collection, bool $holds): void {
		expect($this->c->get(StoreRecords::class)->holds($collection))->toBe($holds);
	})->with([
		['orders', true], ['transactions', true], ['carts', true], ['subscriptions', true],
		['products', false], ['discounts', false], ['no-such-collection', false], ['', false],
	]);
});

describe('at the storage layer', function (): void {
	test('an administrator cannot create, change or delete a store record', function (): void {
		$this->actors->set($this->admin);

		expect(fn () => $this->c->get(ObjectSaver::class)->saveObject('orders', ['id' => 'forged', 'grandTotal' => 1]))->toThrow(AccessDeniedException::class)
			->and(fn () => $this->c->get(ObjectUpdater::class)->updateObject('orders', 'order-one', ['id' => 'order-one', 'notes' => 'edited']))->toThrow(AccessDeniedException::class)
			->and(fn () => $this->c->get(ObjectRemover::class)->deleteObject('orders', 'order-one'))->toThrow(AccessDeniedException::class)
			->and(($this->exists)('orders', 'forged'))->toBeFalse()
			->and(($this->exists)('orders', 'order-one'))->toBeTrue();
	});

	test('nor with authentication switched off', function (): void {
		$config       = $this->c->get(Config::class);
		$config->auth = ['enable' => false] + $config->auth;
		$this->actors->set(Actor::anonymous());

		expect(fn () => $this->c->get(ObjectRemover::class)->deleteObject('orders', 'order-one'))->toThrow(AccessDeniedException::class)
			->and(($this->exists)('orders', 'order-one'))->toBeTrue();
	});

	test('the site itself still writes them', function (): void {
		($this->asSystem)(fn () => $this->c->get(ObjectUpdater::class)->updateObject('orders', 'order-one', ['id' => 'order-one', 'number' => 1001, 'grandTotal' => 2500]));

		expect($this->c->get(ObjectFetcher::class)->fetchObjectFresh('orders', 'order-one')->toArray()['grandTotal'])->toBe(2500);
	});

	test('an administrator still writes everything else', function (): void {
		$this->actors->set($this->admin);
		$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'cup', 'title' => 'Cup', 'status' => 'active', 'price' => 5]);

		expect(($this->exists)('products', 'cup'))->toBeTrue();
	});

	test('a product cannot be cloned into the orders', function (): void {
		$this->actors->set($this->admin);

		expect(fn () => $this->c->get(ObjectCloner::class)->cloneObject(['collection' => 'products', 'id' => 'mug'], ['collection' => 'orders', 'id' => 'cloned']))->toThrow(AccessDeniedException::class)
			->and(($this->exists)('orders', 'cloned'))->toBeFalse();
	});

	test('the factory cannot fill the orders', function (): void {
		$this->actors->set($this->admin);

		expect(fn () => $this->c->get(FactoryImporter::class)->import('orders', 2))->toThrow(AccessDeniedException::class)
			->and($this->c->get(CollectionFetcher::class)->fetchCollection('orders'))->not->toBeNull();
	});
});

describe('for an entry point that asks first', function (): void {
	test('a write is refused as the store\'s own, and a read is not', function (): void {
		$guard = $this->c->get(AccessGuard::class);

		expect($guard->can($this->admin, 'orders', Operation::Update, 'order-one'))->toBe(Decision::DenySystemOnly)
			->and($guard->can($this->admin, 'orders', Operation::Create))->toBe(Decision::DenySystemOnly)
			->and($guard->can($this->admin, 'orders', Operation::Delete, 'order-one'))->toBe(Decision::DenySystemOnly)
			->and($guard->can($this->admin, 'orders', Operation::Import))->toBe(Decision::DenySystemOnly)
			->and($guard->can($this->admin, 'orders', Operation::Read, 'order-one'))->toBe(Decision::Allow)
			->and($guard->can($this->admin, 'products', Operation::Update, 'mug'))->toBe(Decision::Allow)
			->and($guard->can(Actor::system(), 'orders', Operation::Update, 'order-one'))->toBe(Decision::Allow);
	});
});

describe('the import routes', function (): void {
	beforeEach(fn () => signInAs($this->app, 'admin-user-test-com'));

	// A queued import runs later as the site itself, so the route must refuse it.
	test('refuse a store collection, queued or not', function (string $path): void {
		$response = postJson($path, []);

		expect($response->getStatusCode())->toBe(403)
			->and(json_decode((string)$response->getBody(), true)['error']['code'] ?? null)->toBe('commerce_collection_readonly')
			->and(glob(cmsDataDir() . 'orders/*.json'))->toHaveCount(1);
	})->with([
		'/api/import/collections/orders/factory?fqty=2',
		'/api/import/collections/orders/factory?fqty=2&queue=1',
		'/api/import/collections/orders/json',
		'/api/import/collections/orders/csv',
		'/api/import/collections/orders/deck/json',
		'/api/import/collections/orders/deck/csv',
	]);
});

describe('the store\'s own writes are not content changes', function (): void {
	beforeEach(function (): void {
		$this->c->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
		($this->asSystem)(fn () => $this->c->get(ObjectSaver::class)->saveObject('automations', [
			'id'       => 'on-any-change',
			'name'     => 'On any change',
			'enabled'  => true,
			'triggers' => [
				't0' => ['id' => 't0', 'type' => 'event', 'event' => 'object.created'],
				't1' => ['id' => 't1', 'type' => 'event', 'event' => 'object.updated'],
				't2' => ['id' => 't2', 'type' => 'event', 'event' => 'object.deleted'],
			],
			'handler'  => "<?php\n\nreturn function (\$ctx) { return 1; };\n",
		]));
		foreach ((array)glob(cmsDataDir() . '.system/automations/_queue/*.json') as $file) {
			unlink((string)$file);
		}

		$this->queued = fn (): array => array_map(
			static fn (string $file): string => (string)(json_decode((string)file_get_contents($file), true)['event']['collection'] ?? ''),
			(array)glob(cmsDataDir() . '.system/automations/_queue/*.json'),
		);
		// The object events recorded for a collection. Making the collection is recorded too, and should be.
		$this->rows = fn (string $collection): array => array_values(array_filter(
			array_map(static fn ($row): string => $row->event, $this->c->get(ActivityRepository::class)->query(new ActivityFilter(collection: $collection))),
			static fn (string $event): bool => str_starts_with($event, 'object.'),
		));
	});

	test('a cart saved, changed and removed leaves no activity row and starts no automation', function (): void {
		($this->asSystem)(function (): void {
			$this->c->get(ObjectSaver::class)->saveObject('carts', ['id' => 'cart-one', 'status' => 'active']);
			$this->c->get(ObjectUpdater::class)->updateObject('carts', 'cart-one', ['id' => 'cart-one', 'status' => 'checkout']);
			$this->c->get(ObjectRemover::class)->deleteObject('carts', 'cart-one');
		});

		expect(($this->rows)('carts'))->toBe([])
			->and(($this->queued)())->toBe([]);
	});

	test('a product change still does both', function (): void {
		($this->asSystem)(fn () => $this->c->get(ObjectUpdater::class)->updateObject('products', 'mug', ['id' => 'mug', 'title' => 'Big Mug', 'status' => 'active', 'price' => 12.5]));

		expect(($this->rows)('products'))->toContain('object.updated')
			->and(($this->queued)())->toBe(['products']);
	});
});
