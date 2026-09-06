<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\putJson;

/**
 * `publicIncrement: true` on a number field lets anonymous callers use the
 * counter routes for that field and nothing else. The grant lives on the
 * field, not in the collection's publicOperations, so a product can open
 * `likes` and keep `stock` closed.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();

	// The number schema's field is `number`; the collection-level property
	// override is where an operator flips the setting without editing the
	// reserved schema.
	$collection             = new CollectionData();
	$collection->id         = 'likes';
	$collection->name       = 'Likes';
	$collection->schema     = 'number';
	$collection->properties = ['number' => ['field' => 'number', 'settings' => ['publicIncrement' => true]]];
	$container->get(CollectionSaver::class)->saveCollection($collection->toArray());

	$closed             = new CollectionData();
	$closed->id         = 'stock';
	$closed->name       = 'Stock';
	$closed->schema     = 'number';
	$container->get(CollectionSaver::class)->saveCollection($closed->toArray());

	$saver = $container->get(ObjectSaver::class);
	$saver->saveObject('likes', ['id' => 'post', 'number' => 0]);
	$saver->saveObject('stock', ['id' => 'widget', 'number' => 5]);

	// Turn authentication on for the requests below (the suite runs with it
	// off), the same way signInAs() does — without signing anyone in.
	$config         = $container->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;
});

it('lets an anonymous caller increment a field that opts in', function (): void {
	$response = post('/api/collections/likes/post/number/increment');

	$response->assertOk();
	expect(json_decode((string)$response->getBody(), true)['value'] ?? null)->toBe(1);
});

it('refuses the same route on a field that has not opted in', function (): void {
	$response = post('/api/collections/stock/widget/number/increment');

	expect($response->getStatusCode())->toBeIn([401, 403]);
});

it('does not open the rest of the object', function (): void {
	$response = putJson('/api/collections/likes/post', ['id' => 'post', 'number' => 999]);

	expect($response->getStatusCode())->toBeIn([401, 403]);
});
