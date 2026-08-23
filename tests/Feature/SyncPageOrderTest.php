<?php

use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

// The sidebar order and page hierarchy live in .order.json, owned by
// BuilderOrderRepository rather than being part of CollectionData — so it sat
// outside sync entirely. The receiving site kept its own arrangement, and any
// page it had no entry for fell through to reconcile() and landed
// alphabetically at the bottom.
//
// It now travels as `pageOrder` on the collection's SETTINGS: the order is
// configuration rather than content, so it belongs to the same selection the
// operator already makes for settings — tick Pages under Collection Settings
// and its arrangement goes with them. That also means a reorder surfaces as
// the collection differing in the existing diff, rather than needing a
// category of its own that would only ever hold a single row.

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();

	$c->get(SchemaSaver::class)->saveSchema([
		'id'         => 'ordertest',
		'name'       => 'Order Test',
		'type'       => 'object',
		'properties' => [
			'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'title' => ['type' => 'string', 'field' => 'text'],
		],
	]);

	$col         = new CollectionData();
	$col->id     = 'builder-pages';
	$col->name   = 'Pages';
	$col->schema = 'ordertest';
	$c->get(CollectionSaver::class)->saveCollection($col->toArray());

	$saver = $c->get(ObjectSaver::class);
	foreach (['alpha', 'beta', 'gamma'] as $id) {
		$saver->saveObject('builder-pages', ['id' => $id, 'title' => ucfirst($id)]);
	}

	$this->exporter  = $c->get(JumpStartExporter::class);
	$this->importer  = $c->get(JumpStartImporter::class);
	$this->orders    = $c->get(BuilderOrderService::class);
	$this->orderRepo = $c->get(BuilderOrderRepository::class);
	$this->fetcher   = $c->get(CollectionFetcher::class);
});

/** Pull the exported settings entry for a collection out of either bucket. */
function exportedCollection(array $collections, string $id): ?array
{
	foreach (['custom', 'reserved'] as $kind) {
		foreach ($collections[$kind] ?? [] as $entry) {
			if (is_array($entry) && ($entry['id'] ?? null) === $id) {
				return $entry;
			}
		}
	}

	return null;
}

it('exports the page order on the collection settings', function (): void {
	// Deliberately not alphabetical — the point is that a chosen arrangement
	// survives, not that both sides happen to sort the same way.
	$this->orders->write('builder-pages', [
		['id' => 'gamma', 'children' => []],
		['id' => 'alpha', 'children' => []],
		['id' => 'beta', 'children' => []],
	]);

	$data  = $this->exporter->exportSyncData(null, null, [], null);
	$entry = exportedCollection($data->collections, 'builder-pages');

	expect($entry)->not->toBeNull();
	expect(array_column($entry['pageOrder'], 'id'))->toBe(['gamma', 'alpha', 'beta']);
});

it('leaves the settings alone for a collection with no order file', function (): void {
	// Read through the repository, not the service: the service migrates and
	// writes an order file when none exists, and an export must not mutate the
	// site it is reading.
	expect($this->orderRepo->exists('builder-pages'))->toBeFalse();

	$data  = $this->exporter->exportSyncData(null, null, [], null);
	$entry = exportedCollection($data->collections, 'builder-pages');

	expect($entry)->not->toBeNull();
	expect($entry)->not->toHaveKey('pageOrder');
	expect($this->orderRepo->exists('builder-pages'))->toBeFalse();
});

it('applies an imported order that arrived with the settings', function (): void {
	$this->importer->importFromDefinition([
		'collections' => [
			'custom' => [[
				'id'        => 'builder-pages',
				'schema'    => 'ordertest',
				'name'      => 'Pages',
				'pageOrder' => [
					['id' => 'gamma', 'children' => []],
					['id' => 'beta', 'children' => []],
					['id' => 'alpha', 'children' => []],
				],
			]],
		],
	], upsert: true);

	expect(array_column($this->orders->read('builder-pages'), 'id'))->toBe(['gamma', 'beta', 'alpha']);
});

it('applies the order after the pages it arranges are imported', function (): void {
	// Collections are processed BEFORE objects, so an order written at
	// collection time would be reconciled against pages that do not exist yet
	// and stripped entirely. It has to be held back until the end.
	recursiveDelete(cmsDataDir() . '/builder-pages');

	$this->importer->importFromDefinition([
		'collections' => [
			'custom' => [[
				'id'        => 'builder-pages',
				'schema'    => 'ordertest',
				'name'      => 'Pages',
				'pageOrder' => [
					['id' => 'second', 'children' => []],
					['id' => 'first', 'children' => []],
				],
			]],
		],
		'objects' => [
			['collection' => 'builder-pages', 'id' => 'first', 'data' => ['id' => 'first', 'title' => 'First']],
			['collection' => 'builder-pages', 'id' => 'second', 'data' => ['id' => 'second', 'title' => 'Second']],
		],
	], upsert: true);

	expect(array_column($this->orders->read('builder-pages'), 'id'))->toBe(['second', 'first']);
});

it('carries nesting, not just top-level order', function (): void {
	$this->importer->importFromDefinition([
		'collections' => [
			'custom' => [[
				'id'        => 'builder-pages',
				'schema'    => 'ordertest',
				'name'      => 'Pages',
				'pageOrder' => [
					['id' => 'alpha', 'children' => [['id' => 'beta', 'children' => []]]],
					['id' => 'gamma', 'children' => []],
				],
			]],
		],
	], upsert: true);

	$tree = $this->orders->read('builder-pages');

	expect(array_column($tree, 'id'))->toBe(['alpha', 'gamma']);
	expect(array_column($tree[0]['children'], 'id'))->toBe(['beta']);
});

it('reconciles a tree naming pages the destination does not have', function (): void {
	// A stale or partial tree must not orphan a real page or resurrect a
	// deleted one — write() reconciles against what actually exists.
	$this->importer->importFromDefinition([
		'collections' => [
			'custom' => [[
				'id'        => 'builder-pages',
				'schema'    => 'ordertest',
				'name'      => 'Pages',
				'pageOrder' => [
					['id' => 'gamma', 'children' => []],
					['id' => 'ghost', 'children' => []],
				],
			]],
		],
	], upsert: true);

	$ids = array_column($this->orders->read('builder-pages'), 'id');

	expect($ids)->not->toContain('ghost');
	expect($ids)->toContain('alpha')->toContain('beta')->toContain('gamma');
	expect($ids[0])->toBe('gamma');
});

it('never persists pageOrder into the collection settings', function (): void {
	// It is lifted off before CollectionSaver sees it — .meta.json must not
	// grow a field that is not part of CollectionData.
	$this->importer->importFromDefinition([
		'collections' => [
			'custom' => [[
				'id'        => 'builder-pages',
				'schema'    => 'ordertest',
				'name'      => 'Pages',
				'pageOrder' => [['id' => 'gamma', 'children' => []]],
			]],
		],
	], upsert: true);

	$collection = $this->fetcher->fetchCollection('builder-pages');

	expect($collection)->not->toBeNull();
	expect($collection->toArray())->not->toHaveKey('pageOrder');
});
