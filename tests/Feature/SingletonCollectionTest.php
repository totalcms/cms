<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Service\SingletonCollectionResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Valid `blog`-schema object data (it requires title/created/updated).
 *
 * @param array<string,mixed> $overrides
 *
 * @return array<string,mixed>
 */
function validBlogObject(array $overrides = []): array
{
	return array_merge([
		'title'   => 'Settings',
		'created' => '2026-06-08T00:00:00+00:00',
		'updated' => '2026-06-08T00:00:00+00:00',
	], $overrides);
}

function seedSingletonCollection(object $container, string $id): CollectionData
{
	$collection            = new CollectionData();
	$collection->id        = $id;
	$collection->name      = ucfirst($id);
	$collection->schema    = 'blog';
	$collection->singleton = true;
	$container->get(CollectionSaver::class)->saveCollection($collection->toArray());

	return $container->get(CollectionFetcher::class)->fetchCollection($id);
}

test('an empty singleton is active and resolves to no object (routes to the new form)', function (): void {
	$container      = $this->app->getContainer();
	$collectionData = seedSingletonCollection($container, 'sgl');
	$resolver       = $container->get(SingletonCollectionResolver::class);

	expect($collectionData)->not->toBeNull();
	expect($resolver->isActive($collectionData))->toBeTrue();
	expect($resolver->resolveTarget($collectionData))->toBeNull(); // empty → add form
});

test('ObjectSaver forces a singleton object id to the collection id', function (): void {
	$container = $this->app->getContainer();
	seedSingletonCollection($container, 'sgl2');

	// Submit a different id — it must be forced to the collection id.
	$container->get(ObjectSaver::class)->saveObject('sgl2', validBlogObject(['id' => 'whatever']));

	$fetcher = $container->get(ObjectFetcher::class);
	expect($fetcher->existsObject('sgl2', 'sgl2'))->toBeTrue();
	expect($fetcher->existsObject('sgl2', 'whatever'))->toBeFalse();
});

test('ObjectSaver blocks a second object in a singleton', function (): void {
	$container = $this->app->getContainer();
	seedSingletonCollection($container, 'sgl3');

	$saver = $container->get(ObjectSaver::class);
	$saver->saveObject('sgl3', validBlogObject(['id' => 'sgl3'])); // first object — allowed

	expect(fn () => $saver->saveObject('sgl3', validBlogObject(['id' => 'another'])))
		->toThrow(DomainException::class);
});

test('resolveTarget returns the object id once a singleton holds its object', function (): void {
	$container      = $this->app->getContainer();
	$collectionData = seedSingletonCollection($container, 'sgl4');
	$container->get(ObjectSaver::class)->saveObject('sgl4', validBlogObject(['id' => 'sgl4']));

	$resolver = $container->get(SingletonCollectionResolver::class);
	expect($resolver->resolveTarget($collectionData))->toBe('sgl4');
});
