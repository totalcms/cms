<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();

	// A schema whose ID is an oid-autogen pattern — the exact case that breaks.
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'widget',
		'description' => 'OID autogen test schema',
		'properties'  => [
			'id' => [
				'type'     => 'string',
				'label'    => 'ID',
				'field'    => 'input',
				'settings' => ['autogen' => '${oid-00000}'],
			],
			'title' => [
				'type'  => 'string',
				'label' => 'Title',
				'field' => 'input',
			],
		],
		'required' => ['title'],
		'index'    => ['title'],
	]);

	$c->get(CollectionSaver::class)->saveCollection([
		'id'     => 'widgets',
		'name'   => 'Widgets',
		'schema' => 'widget',
	]);
});

/**
 * Mirror exactly what CsvImporter / JsonImporter do on a batch import:
 * suspend events + index, loop importObject() (catching per-object like the real
 * importers), then dispatch import.completed.
 *
 * @return array<string> created ids
 */
function runBatchImport(object $container, string $collection, int $count): array
{
	$container->get(EventDispatcher::class)->suspendForImport($collection);
	$container->get(IndexBuildListener::class)->suspendForCollection($collection);

	$created = [];
	for ($i = 1; $i <= $count; $i++) {
		try {
			$created[] = $container->get(ObjectImporter::class)
				->importObject($collection, ['title' => "Widget {$i}"])
				->id;
		} catch (\Throwable) {
			// Real importers log + continue on a per-object failure.
		}
	}

	$container->get(EventDispatcher::class)->dispatch(
		CoreEvent::IMPORT_COMPLETED,
		new ImportEventPayload($collection, count($created), $created),
	);

	return $created;
}

it('gives each imported object a unique sequential OID on a batch import', function (): void {
	$created = runBatchImport($this->app->getContainer(), 'widgets', 3);

	// Before the fix: object 2+ collide on OID 00001 and fail, so only 1 lands.
	expect($created)->toHaveCount(3);
	expect(array_unique($created))->toHaveCount(3);
	expect($created)->toBe(['00001', '00002', '00003']);
});

it('persists the collection count after a batch import', function (): void {
	runBatchImport($this->app->getContainer(), 'widgets', 3);

	// Before the fix: count never increments, so the next import repeats OID 1 forever.
	$collection = $this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('widgets');
	expect($collection->count)->toBe(3);
});

it('gives unique OIDs and persists count for standalone (queued) imports', function (): void {
	// No suspendForImport: each importObject owns its own lifecycle, exactly
	// like JobRunner processing one queued import job at a time.
	$container = $this->app->getContainer();
	$importer  = $container->get(ObjectImporter::class);

	$ids = [
		$importer->importObject('widgets', ['title' => 'A'])->id,
		$importer->importObject('widgets', ['title' => 'B'])->id,
		$importer->importObject('widgets', ['title' => 'C'])->id,
	];

	expect($ids)->toBe(['00001', '00002', '00003']);
	expect($container->get(CollectionFetcher::class)->fetchCollection('widgets')->count)->toBe(3);
});

it('continues sequential OIDs across two separate imports', function (): void {
	$container = $this->app->getContainer();

	$first  = runBatchImport($container, 'widgets', 2);
	$second = runBatchImport($container, 'widgets', 2);

	expect($first)->toBe(['00001', '00002']);
	// Before the fix: the second import restarts at 00001 and fails entirely.
	expect($second)->toBe(['00003', '00004']);
	expect($container->get(CollectionFetcher::class)->fetchCollection('widgets')->count)->toBe(4);
});
