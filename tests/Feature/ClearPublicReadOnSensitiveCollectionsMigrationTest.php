<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Migration\Migration\ClearPublicReadOnSensitiveCollectionsMigration;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * 3.0.41–3.0.42 defaulted new collections to public Read. The migration takes
 * it back off the collections that must never be read anonymously — matched
 * by schema, so renamed ones are covered — and leaves everything else alone.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();

	/** @param list<string> $operations */
	$this->publicOps = function (string $id, array $operations): void {
		$this->container->get(CollectionFetcher::class)->fetchOrCreateReserved($id);
		$this->container->get(CollectionSaver::class)->patchCollection($id, ['publicOperations' => $operations]);
	};

	$this->storedOps = fn (string $id): mixed => json_decode(
		(string)file_get_contents(cmsDataDir() . "$id/.meta.json"),
		true,
	)['publicOperations'] ?? null;
});

it('removes public Read from every sensitive collection', function (): void {
	foreach (['auth', 'playground', 'mailer', 'automations', 'dataviews'] as $id) {
		($this->publicOps)($id, ['read']);
	}
	// A Site Builder pages collection under a site-chosen name.
	$this->container->get(CollectionSaver::class)->saveCollection([
		'id' => 'site-pages', 'name' => 'Pages', 'schema' => 'builder-page', 'publicOperations' => ['read'],
	]);

	$cleared = $this->container->get(ClearPublicReadOnSensitiveCollectionsMigration::class)->run();

	expect($cleared)->toBe(6);
	foreach (['auth', 'playground', 'mailer', 'automations', 'dataviews', 'site-pages'] as $id) {
		expect(($this->storedOps)($id))->toBe([], "$id kept public Read");
	}
});

it('covers a user collection whose schema inherits from auth', function (): void {
	$this->container->get(SchemaSaver::class)->saveSchema([
		'id'          => 'member',
		'type'        => 'object',
		'description' => 'Site members.',
		'inheritFrom' => ['auth'],
		'properties'  => [
			'company' => ['type' => 'string', 'field' => 'text', 'label' => 'Company'],
		],
	]);
	$this->container->get(CollectionSaver::class)->saveCollection([
		'id' => 'members', 'name' => 'Members', 'schema' => 'member', 'publicOperations' => ['read'],
	]);

	$this->container->get(ClearPublicReadOnSensitiveCollectionsMigration::class)->run();

	expect(($this->storedOps)('members'))->toBe([]);
});

it('removes only Read and keeps the other public operations', function (): void {
	($this->publicOps)('auth', ['create', 'read', 'read', 'update']);

	$this->container->get(ClearPublicReadOnSensitiveCollectionsMigration::class)->run();

	expect(($this->storedOps)('auth'))->toBe(['create', 'update']);
});

it('leaves content collections public', function (): void {
	$this->container->get(CollectionSaver::class)->saveCollection([
		'id' => 'news', 'name' => 'News', 'schema' => 'blog', 'publicOperations' => ['read'],
	]);

	$cleared = $this->container->get(ClearPublicReadOnSensitiveCollectionsMigration::class)->run();

	expect($cleared)->toBe(0)
		->and(($this->storedOps)('news'))->toBe(['read']);
});
