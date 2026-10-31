<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Tests\Fakes\PausingObjectFetcher;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Factory\LoggerFactory;

/*
 * A full rebuild reads every object file. With Queue Rebuild on Save that
 * runs from cron while saves keep coming, so it must not hold the index lock
 * while it reads (every save's append would wait the whole rebuild out), and
 * it must not write back what it read if a file changed in the meantime.
 * Each test acts once, right after the build's first read.
 */

function rebuildCollectionDir(): string
{
	return collectionPath('rebuildnotes');
}

function writeRebuildNote(string $id, string $title): void
{
	file_put_contents(rebuildCollectionDir() . "{$id}.json", (string)json_encode(['id' => $id, 'title' => $title]));
}

/**
 * Notes written a minute ago, so only what a test changes during the build
 * counts as changed: otherwise every file has this second's time and the
 * build reads them all again under the lock, which proves nothing.
 */
function writeOldRebuildNotes(int $count): void
{
	for ($i = 0; $i < $count; $i++) {
		writeRebuildNote("note-{$i}", "Note {$i}");
		touch(rebuildCollectionDir() . "note-{$i}.json", time() - 60);
	}
}

/** @return array<string,string> id => title, from the index on disk */
function rebuiltTitles(): array
{
	$index  = json_decode((string)file_get_contents(rebuildCollectionDir() . '.index.json'), true);
	$titles = [];
	foreach ($index['objects'] as $entry) {
		$titles[(string)$entry['id']] = (string)($entry['title'] ?? '');
	}

	return $titles;
}

/** An IndexBuilder whose reads call $afterFirstRead once, after the first object is read. */
function pausingIndexBuilder(ContainerInterface $container, Closure $afterFirstRead): IndexBuilder
{
	$done      = false;
	$fetcher   = new PausingObjectFetcher(
		$container->get(ObjectRepository::class),
		function (string $collection, string $id) use (&$done, $afterFirstRead): void {
			if (!$done) {
				$done = true;
				$afterFirstRead($id);
			}
		},
	);

	return new IndexBuilder(
		$container->get(IndexRepository::class),
		$fetcher,
		$container->get(SchemaFetcher::class),
		$container->get(CollectionFetcher::class),
		$container->get(CollectionSaver::class),
		$container->get(JobQueuer::class),
		$container->get(LoggerFactory::class),
	);
}

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	if (is_dir(rebuildCollectionDir())) {
		exec('/bin/rm -rf ' . escapeshellarg(rebuildCollectionDir()));
	}

	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'rebuildnote',
		'type'       => 'object',
		'properties' => [
			'id'    => ['type' => 'string', 'field' => 'id'],
			'title' => ['type' => 'string', 'field' => 'text'],
		],
		'index' => ['id', 'title'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'rebuildnotes', 'name' => 'Rebuild Notes', 'schema' => 'rebuildnote']);
});

afterEach(function (): void {
	if (is_dir(rebuildCollectionDir())) {
		exec('/bin/rm -rf ' . escapeshellarg(rebuildCollectionDir()));
	}
});

dataset('collection sizes', [
	'standard build' => [3],
	'streamed build' => [520],
]);

test('a rebuild does not hold the index lock while it reads the files', function (int $size): void {
	writeOldRebuildNotes($size);
	$probes = [];
	$lock   = rebuildCollectionDir() . '.index.json.lock';

	pausingIndexBuilder($this->app->getContainer(), function () use ($lock, &$probes): void {
		$code     = sprintf('$h = fopen(%s, "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "free" : "held";', var_export($lock, true));
		$probes[] = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
	})->buildIndex('rebuildnotes');

	expect($probes)->toBe(['free']);
	expect(rebuiltTitles())->toHaveCount($size);
})->with('collection sizes');

test('a rebuild keeps a create made while it read the files', function (int $size): void {
	writeOldRebuildNotes($size);

	pausingIndexBuilder($this->app->getContainer(), function (): void {
		writeRebuildNote('zz-late', 'Late');
	})->buildIndex('rebuildnotes');

	expect(rebuiltTitles())->toHaveKey('zz-late')->toHaveCount($size + 1);
})->with('collection sizes');

test('a rebuild keeps an edit made after it read the file', function (int $size): void {
	writeOldRebuildNotes($size);
	$edited = null;

	pausingIndexBuilder($this->app->getContainer(), function (string $id) use (&$edited): void {
		$edited = $id;
		writeRebuildNote($id, 'Edited');
	})->buildIndex('rebuildnotes');

	expect(rebuiltTitles()[$edited])->toBe('Edited');
})->with('collection sizes');

test('a rebuild drops an object deleted after it read the file', function (int $size): void {
	writeOldRebuildNotes($size);
	$deleted = null;

	pausingIndexBuilder($this->app->getContainer(), function (string $id) use (&$deleted): void {
		$deleted = $id;
		unlink(rebuildCollectionDir() . "{$id}.json");
	})->buildIndex('rebuildnotes');

	expect(rebuiltTitles())->not->toHaveKey($deleted)->toHaveCount($size - 1);
})->with('collection sizes');

test('a rebuild indexes the same entries either way', function (int $size): void {
	writeOldRebuildNotes($size);

	pausingIndexBuilder($this->app->getContainer(), function (): void {
	})->buildIndex('rebuildnotes');

	$titles = rebuiltTitles();
	expect($titles)->toHaveCount($size);
	expect($titles['note-0'])->toBe('Note 0');
})->with('collection sizes');

test('a rebuild reads an unchanged object once', function (int $size): void {
	writeOldRebuildNotes($size);
	$reads = 0;
	$container = $this->app->getContainer();
	$builder   = new IndexBuilder(
		$container->get(IndexRepository::class),
		new PausingObjectFetcher($container->get(ObjectRepository::class), function () use (&$reads): void {
			$reads++;
		}),
		$container->get(SchemaFetcher::class),
		$container->get(CollectionFetcher::class),
		$container->get(CollectionSaver::class),
		$container->get(JobQueuer::class),
		$container->get(LoggerFactory::class),
	);

	$builder->buildIndex('rebuildnotes');

	expect($reads)->toBe($size);
	expect($builder->lastIndexedCount())->toBe($size);
})->with('collection sizes');

test('a rebuild keeps an edit whose file time is just before the build started', function (int $size): void {
	// A save writes its temp file, then renames it into place; the file keeps
	// the temp file's time. Written a second before the build began and
	// renamed after the build read the old file, it must still be read again.
	writeOldRebuildNotes($size);
	$edited = null;

	pausingIndexBuilder($this->app->getContainer(), function (string $id) use (&$edited): void {
		$edited = $id;
		writeRebuildNote($id, 'Edited');
		touch(rebuildCollectionDir() . "{$id}.json", time() - 1);
	})->buildIndex('rebuildnotes');

	expect(rebuiltTitles()[$edited])->toBe('Edited');
})->with('collection sizes');
