<?php

declare(strict_types=1);

use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\delete;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\patchJson;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

/*
 * Orders, transactions, carts and subscriptions are records of money. Only
 * the store writes them. The ordinary collection API refuses every change to
 * them from every caller, the site's own administrators included, and none of
 * them, nor the discount codes, can be opened to the public.
 */

const ORDER = [
	'id' => 'order-one', 'number' => 1001, 'email' => 'buyer@example.com', 'currency' => 'USD', 'grandTotal' => 1999, 'paymentStatus' => 'paid',
];

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();

	$this->c->get(CommerceInstaller::class)->install();
	$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectSaver::class)->saveObject('orders', ORDER));

	$this->code   = fn ($response): ?string => json_decode((string)$response->getBody(), true)['error']['code'] ?? null;
	$this->stored = fn (): array => $this->c->get(ObjectFetcher::class)->fetchObject('orders', 'order-one')->toArray();
});

describe('as a super admin', function (): void {
	beforeEach(fn () => signInAs($this->app, 'admin-user-test-com'));

	test('cannot create a record the store writes', function (string $collection): void {
		$response = postJson("/api/collections/{$collection}", ['id' => 'forged', 'email' => 'me@example.com', 'grandTotal' => 1]);

		expect($response->getStatusCode())->toBe(403)
			->and(($this->code)($response))->toBe('commerce_collection_readonly')
			->and($this->c->get(ObjectFetcher::class)->existsObject($collection, 'forged'))->toBeFalse();
	})->with(['orders', 'transactions', 'carts', 'subscriptions']);

	test('cannot change an order by any route', function (string $method, string $path, array $body): void {
		$response = match ($method) {
			'PUT'    => putJson($path, $body),
			'PATCH'  => patchJson($path, $body),
			'POST'   => postJson($path, $body),
			'DELETE' => delete($path),
		};

		expect($response->getStatusCode())->toBe(403)
			->and(($this->code)($response))->toBe('commerce_collection_readonly')
			->and(($this->stored)()['grandTotal'])->toBe(1999)
			->and(($this->stored)()['notes'] ?? '')->toBe('');
	})->with([
		'replace'         => ['PUT', '/api/collections/orders/order-one', ['id' => 'order-one', 'grandTotal' => 1]],
		'patch'           => ['PATCH', '/api/collections/orders/order-one', ['notes' => 'edited']],
		'delete'          => ['DELETE', '/api/collections/orders/order-one', []],
		'bulk delete'     => ['POST', '/api/collections/orders/bulk/delete', ['ids' => ['order-one']]],
		'clone'           => ['POST', '/api/collections/orders/order-one/clone', []],
		'property'        => ['PUT', '/api/collections/orders/order-one/notes', ['notes' => 'edited']],
		'property patch'  => ['PATCH', '/api/collections/orders/order-one/notes', ['notes' => 'edited']],
		'property delete' => ['DELETE', '/api/collections/orders/order-one/notes', []],
		'counter'         => ['POST', '/api/collections/orders/order-one/grandTotal/increment/5', []],
		'line added'      => ['POST', '/api/collections/orders/order-one/lines/deck', ['id' => 'l9', 'productId' => 'x']],
	]);

	test('can still read orders', function (): void {
		expect(get('/api/collections/orders/order-one')->getStatusCode())->toBe(200)
			->and(get('/api/collections/orders')->getStatusCode())->toBe(200)
			->and(get('/api/collections/orders/query')->getStatusCode())->toBe(200);
	});

	test('can rebuild the index, which changes no record', function (): void {
		expect(putJson('/api/collections/orders/index', [])->getStatusCode())->toBeIn([200, 202]);
	});

	test('edits products and discount codes like any collection', function (string $collection, array $object): void {
		expect(postJson("/api/collections/{$collection}", $object)->getStatusCode())->toBeIn([200, 201])
			->and(patchJson("/api/collections/{$collection}/{$object['id']}", ['title' => 'Renamed'])->getStatusCode())->toBe(200)
			->and(delete("/api/collections/{$collection}/{$object['id']}")->getStatusCode())->toBeIn([200, 204]);
	})->with([
		'products'  => ['products', ['id' => 'mug', 'title' => 'Mug', 'price' => 12.5]],
		'discounts' => ['discounts', ['id' => 'spring', 'type' => 'percent', 'value' => 10]],
	]);

	test('cannot open a store collection to the public', function (string $collection, string $method): void {
		$body     = ['id' => $collection, 'name' => 'Open', 'schema' => $this->c->get(CollectionFetcher::class)->fetchCollection($collection)->schema, 'publicOperations' => ['read']];
		$response = $method === 'PUT' ? putJson("/api/collections/{$collection}", $body) : patchJson("/api/collections/{$collection}", ['publicOperations' => ['read']]);

		$this->c->get(CollectionFetcher::class)->clearCache($collection);

		expect($response->getStatusCode())->toBe(403)
			->and(($this->code)($response))->toBe('commerce_collection_private')
			->and($this->c->get(CollectionFetcher::class)->fetchCollection($collection)->publicOperations)->toBe([]);
	})->with(['orders', 'transactions', 'carts', 'subscriptions', 'discounts'])->with(['PUT', 'PATCH']);

	test('cannot create a store collection that is open to the public', function (): void {
		$response = postJson('/api/collections', ['id' => 'orders-two', 'name' => 'Orders Two', 'schema' => 'commerce-order', 'publicOperations' => ['read']]);

		expect($response->getStatusCode())->toBe(403)
			->and(($this->code)($response))->toBe('commerce_collection_private')
			->and($this->c->get(CollectionFetcher::class)->collectionExists('orders-two'))->toBeFalse();
	});

	test('can change other settings of a store collection', function (): void {
		expect(patchJson('/api/collections/orders', ['name' => 'Sales', 'publicOperations' => []])->getStatusCode())->toBe(200);

		$this->c->get(CollectionFetcher::class)->clearCache('orders');
		expect($this->c->get(CollectionFetcher::class)->fetchCollection('orders')->name)->toBe('Sales');
	});

	test('can open products to public reading, which a storefront may need', function (): void {
		expect(patchJson('/api/collections/products', ['publicOperations' => ['read']])->getStatusCode())->toBe(200);
	});

	test('cannot let the public count stock up or down', function (): void {
		$response = patchJson('/api/collections/products', ['properties' => ['stock' => ['field' => 'number', 'settings' => ['publicIncrement' => true]]]]);

		expect($response->getStatusCode())->toBe(403)
			->and(($this->code)($response))->toBe('commerce_stock_not_public');
	});

	test('can set stock rules that keep the counter closed', function (): void {
		expect(patchJson('/api/collections/products', ['properties' => ['stock' => ['field' => 'number', 'settings' => ['publicIncrement' => false]]]])->getStatusCode())->toBe(200);
	});

	test('stock cannot be a public counter on a custom schema that inherits the inventory part', function (): void {
		$this->c->get(SchemaSaver::class)->saveSchema(['id' => 'gear', 'type' => 'object', 'inheritFrom' => ['commerce-product-base', 'commerce-product-inventory']]);
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'gear', 'name' => 'Gear', 'schema' => 'gear']);

		$response = patchJson('/api/collections/gear', ['properties' => ['stock' => ['field' => 'number', 'settings' => ['publicIncrement' => true]]]]);

		expect($response->getStatusCode())->toBe(403)
			->and(($this->code)($response))->toBe('commerce_stock_not_public');
	});

	test('stock is not guarded on a custom schema that has no stock to count', function (): void {
		$this->c->get(SchemaSaver::class)->saveSchema(['id' => 'tracts', 'type' => 'object', 'inheritFrom' => ['commerce-product-base']]);
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'tracts', 'name' => 'Tracts', 'schema' => 'tracts']);

		$response = patchJson('/api/collections/tracts', ['properties' => ['stock' => ['field' => 'number', 'settings' => ['publicIncrement' => true]]]]);

		expect(($this->code)($response))->not->toBe('commerce_stock_not_public');
	});

	test('a collection on an order schema is guarded whatever it is called', function (): void {
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'archive', 'name' => 'Archive', 'schema' => 'commerce-order']);

		expect(postJson('/api/collections/archive', ['id' => 'forged'])->getStatusCode())->toBe(403);
	});
});

test("a site's own collection that happens to be called orders is left alone", function (): void {
	// A live site may have had an `orders` collection long before Commerce.
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'orders', 'name' => 'Work Orders', 'schema' => 'text']);
	signInAs($this->app, 'admin-user-test-com');

	expect(postJson('/api/collections/orders', ['id' => 'job-1', 'text' => 'Fix the gate'])->getStatusCode())->toBeIn([200, 201])
		->and(patchJson('/api/collections/orders/job-1', ['text' => 'Fixed'])->getStatusCode())->toBe(200)
		->and(patchJson('/api/collections/orders', ['publicOperations' => ['read']])->getStatusCode())->toBe(200)
		->and(delete('/api/collections/orders/job-1')->getStatusCode())->toBeIn([200, 204]);
});

test('an API key with every permission cannot write an order either', function (): void {
	$config       = $this->c->get(Config::class);
	$config->auth = ['enable' => true] + $config->auth;
	$key          = $this->c->get(ApiKeyCreator::class)->createApiKey('everything', ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['*']])->key;

	$response = postJson('/api/collections/orders', ['id' => 'forged', 'grandTotal' => 1], ['X-API-Key' => $key]);

	expect($response->getStatusCode())->toBe(403)
		->and(($this->code)($response))->toBe('commerce_collection_readonly')
		->and(delete('/api/collections/orders/order-one', [], ['X-API-Key' => $key])->getStatusCode())->toBe(403);
});

test('the guard does not depend on authentication being on', function (): void {
	// With auth off every caller is trusted, and an order is still not theirs to edit.
	$response = patchJson('/api/collections/orders/order-one', ['grandTotal' => 1]);

	expect($response->getStatusCode())->toBe(403)
		->and(($this->stored)()['grandTotal'])->toBe(1999);
});

test('a visitor reads no orders and no discount codes', function (string $path): void {
	$config       = $this->c->get(Config::class);
	$config->auth = ['enable' => true] + $config->auth;

	expect(get($path)->getStatusCode())->toBeIn([401, 403]);
})->with(['/api/collections/discounts', '/api/collections/orders', '/api/collections/orders/order-one', '/api/collections/orders/query']);

test('a visitor cannot write an order', function (): void {
	$config       = $this->c->get(Config::class);
	$config->auth = ['enable' => true] + $config->auth;

	expect(post('/api/collections/orders')->getStatusCode())->toBeIn([401, 403]);
});
