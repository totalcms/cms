<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/*
 * The index append/remove done on every save (Queue Rebuild on Save) reads,
 * changes and writes back the whole index. Two saves at once used to each
 * write over the other's change, and the read came from cache, so even one
 * save could drop an entry another process had just added.
 */

function removeConcurrencyCollection(): void
{
	if (is_dir(collectionPath('racenotes'))) {
		exec('/bin/rm -rf ' . escapeshellarg(collectionPath('racenotes')));
	}
}

/** @return list<string> */
function indexIdsOnDisk(): array
{
	$index = json_decode((string)file_get_contents(collectionPath('racenotes') . '.index.json'), true);

	return array_values(array_map(static fn (array $o): string => (string)$o['id'], $index['objects']));
}

/** Add an entry to the index file behind the repository's back, leaving its cache stale. */
function addEntryBehindTheCache(string $id): void
{
	$file  = collectionPath('racenotes') . '.index.json';
	$index = json_decode((string)file_get_contents($file), true);
	$index['objects'][] = ['id' => $id, 'title' => strtoupper($id)];
	file_put_contents($file, (string)json_encode($index));
	file_put_contents(collectionPath('racenotes') . "{$id}.json", (string)json_encode(['id' => $id, 'title' => strtoupper($id)]));
}

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	removeConcurrencyCollection();

	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'racenote',
		'type'       => 'object',
		'properties' => [
			'id'    => ['type' => 'string', 'field' => 'id'],
			'title' => ['type' => 'string', 'field' => 'text'],
		],
		'index' => ['id', 'title'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'racenotes', 'name' => 'Race Notes', 'schema' => 'racenote']);

	$saver = $container->get(ObjectSaver::class);
	foreach (['a', 'b'] as $id) {
		$saver->saveObject('racenotes', ['id' => $id, 'title' => strtoupper($id)]);
	}

	$this->builder = $container->get(IndexBuilder::class);
	$this->fetcher = $container->get(ObjectFetcher::class);
	// Warm the index cache, as a request that listed the collection would.
	expect($container->get(IndexRepository::class)->fetchIndex('racenotes')?->objects->count())->toBe(2);
});

afterEach(function (): void {
	removeConcurrencyCollection();
});

test('append keeps an entry written since the index was cached', function (): void {
	addEntryBehindTheCache('c');
	file_put_contents(collectionPath('racenotes') . 'd.json', (string)json_encode(['id' => 'd', 'title' => 'D']));

	$this->builder->appendObjectToIndex('racenotes', $this->fetcher->fetchObjectFromDisk('racenotes', 'd'));

	expect(indexIdsOnDisk())->toEqualCanonicalizing(['a', 'b', 'c', 'd']);
});

test('remove keeps an entry written since the index was cached', function (): void {
	addEntryBehindTheCache('c');

	$this->builder->removeObjectFromIndex('racenotes', 'a');

	expect(indexIdsOnDisk())->toEqualCanonicalizing(['b', 'c']);
});

test('append waits for another process that holds the index lock', function (): void {
	file_put_contents(collectionPath('racenotes') . 'd.json', (string)json_encode(['id' => 'd', 'title' => 'D']));
	$object = $this->fetcher->fetchObjectFromDisk('racenotes', 'd');
	[$proc, $pipes] = holdLockInChild(collectionPath('racenotes') . '.index.json.lock', 1.0);

	$start = microtime(true);
	$this->builder->appendObjectToIndex('racenotes', $object);
	$waited = microtime(true) - $start;

	fclose($pipes[1]);
	proc_close($proc);
	expect($waited)->toBeGreaterThan(0.8);
});

test('a rebuild waits for another process that holds the index lock', function (): void {
	[$proc, $pipes] = holdLockInChild(collectionPath('racenotes') . '.index.json.lock', 1.0);

	$start = microtime(true);
	$this->builder->buildIndex('racenotes');
	$waited = microtime(true) - $start;

	fclose($pipes[1]);
	proc_close($proc);
	expect($waited)->toBeGreaterThan(0.8);
	expect(indexIdsOnDisk())->toEqualCanonicalizing(['a', 'b']);
});

test('a metadata change waits for another process that holds the meta lock', function (): void {
	$saver  = $this->app->getContainer()->get(CollectionSaver::class);
	$before = json_decode((string)file_get_contents(collectionPath('racenotes') . '.meta.json'), true);
	[$proc, $pipes] = holdLockInChild(collectionPath('racenotes') . '.meta.json.lock', 1.0);

	$start = microtime(true);
	$saver->incrementObjectCounts('racenotes');
	$waited = microtime(true) - $start;

	fclose($pipes[1]);
	proc_close($proc);
	$after = json_decode((string)file_get_contents(collectionPath('racenotes') . '.meta.json'), true);
	expect($waited)->toBeGreaterThan(0.8);
	expect($after['totalObjects'])->toBe($before['totalObjects'] + 1);
});

test('collection.updated fires after the meta lock is released', function (): void {
	// Listeners can save to the same collection (an automation reading its
	// index triggers a rebuild that patches totalObjects). Fired under the
	// lock, that second save waited on the first forever.
	$container = $this->app->getContainer();
	$lockFile  = collectionPath('racenotes') . '.meta.json.lock';
	$seen      = [];
	$container->get(EventDispatcher::class)->listen(CoreEvent::COLLECTION_UPDATED, function () use ($lockFile, &$seen): void {
		$code   = sprintf('$h = fopen(%s, "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "free" : "held";', var_export($lockFile, true));
		$seen[] = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
	});

	$container->get(CollectionSaver::class)->incrementObjectCounts('racenotes');

	expect($seen)->not->toBeEmpty();
	expect(array_unique($seen))->toBe(['free']);
});

test('the recount after a rebuild sees a file written after the rebuild listed the folder', function (): void {
	// A rebuild used to patch totalObjects with the count it took when it
	// listed the files, wiping out a create that landed in between.
	$saver = $this->app->getContainer()->get(CollectionSaver::class);
	addEntryBehindTheCache('c');

	$saver->recountTotalObjects('racenotes');

	$meta = json_decode((string)file_get_contents(collectionPath('racenotes') . '.meta.json'), true);
	expect($meta['totalObjects'])->toBe(3);
});

test('a settings save cannot lower the lifetime count', function (): void {
	// updateCollection() used to write the caller's snapshot, undoing creates
	// committed since; a lower count reissues an ${oid} already in use.
	$saver    = $this->app->getContainer()->get(CollectionSaver::class);
	$snapshot = $this->app->getContainer()->get(\TotalCMS\Domain\Collection\Repository\CollectionRepository::class)->fetchCollection('racenotes');
	$saver->incrementObjectCounts('racenotes', 3);
	$counted = json_decode((string)file_get_contents(collectionPath('racenotes') . '.meta.json'), true)['count'];

	$saver->updateCollection('racenotes', array_merge($snapshot->toArray(), ['name' => 'Renamed']), $snapshot);

	$meta = json_decode((string)file_get_contents(collectionPath('racenotes') . '.meta.json'), true);
	expect($meta['name'])->toBe('Renamed');
	expect($meta['count'])->toBe($counted);
});
