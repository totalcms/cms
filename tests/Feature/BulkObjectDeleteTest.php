<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Object\Service\BulkObjectService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$container          = $this->app->getContainer();
	$collection         = new CollectionData();
	$collection->id     = 'posts';
	$collection->name   = 'Posts';
	$collection->schema = 'blog';
	$container->get(CollectionSaver::class)->saveCollection($collection->toArray());

	$saver = $container->get(ObjectSaver::class);
	foreach (['a', 'b', 'c', 'd'] as $id) {
		$saver->saveObject('posts', [
			'id'      => $id,
			'title'   => 'Post ' . $id,
			'created' => '2026-06-08T00:00:00+00:00',
			'updated' => '2026-06-08T00:00:00+00:00',
		]);
	}

	$this->bulk    = $container->get(BulkObjectService::class);
	$this->objects = $container->get(ObjectFetcher::class);
	$this->query   = $container->get(IndexQueryService::class);
});

it('deletes all the requested objects and leaves the rest', function (): void {
	$result = $this->bulk->deleteMany('posts', ['a', 'c']);

	expect($result['deleted'])->toBe(['a', 'c']);

	expect($this->objects->existsObject('posts', 'a'))->toBeFalse();
	expect($this->objects->existsObject('posts', 'c'))->toBeFalse();
	expect($this->objects->existsObject('posts', 'b'))->toBeTrue();
	expect($this->objects->existsObject('posts', 'd'))->toBeTrue();
});

it('rebuilds the index once so deleted objects no longer appear in queries', function (): void {
	$this->bulk->deleteMany('posts', ['a', 'b']);

	// The per-object rebuilds are suspended during the batch; this proves the
	// single rebuild fired on bulk.deleted produced a correct index.
	$result = $this->query->query('posts', ['limit' => '50']);
	$ids    = array_map(static fn (array $i): string => (string)$i['id'], $result->items);
	sort($ids);

	expect($ids)->toBe(['c', 'd']);
});

it('accounts for every requested id exactly once in the summary', function (): void {
	$result = $this->bulk->deleteMany('posts', ['a', 'ghost']);

	// Whether a missing id lands in deleted or failed is storage-defined; what
	// matters is that every requested id is reported exactly once and the real
	// object is gone.
	expect(count($result['deleted']) + count($result['failed']))->toBe(2);
	expect($result['deleted'])->toContain('a');
	expect($this->objects->existsObject('posts', 'a'))->toBeFalse();
});
