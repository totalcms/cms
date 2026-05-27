<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Repository;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Regression coverage for the directory-name ↔ collection-id consistency check
 * added in `CollectionRepository::fetchCollection()`.
 *
 * The original bug: an operator left a manual backup directory (e.g.
 * `dataviews-bkp/` copied from `dataviews/`) under tcms-data. Both meta files
 * carried the same id, so `listAllCollections()` returned `dataviews` twice
 * and downstream registrars (notably the MCP resource registrar) crashed on
 * the duplicate URI.
 *
 * The fix: `fetchCollection($dir)` returns null when the meta's `id` field
 * doesn't equal `$dir`. Stale backup directories become invisible to
 * `listAllCollections()` and the MCP server boots cleanly.
 */
beforeEach(function (): void {
	$this->tmpRoot = sys_get_temp_dir() . '/tcms-coll-repo-' . uniqid();
	mkdir($this->tmpRoot, 0755, true);

	$flysystem = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
	$storage   = new StorageFilesystemAdapter($flysystem);

	$this->repo = new CollectionRepository(
		$storage,
		new CollectionFactory(),
		$this->createMock(SchemaValidator::class),
		$this->createMock(CacheManager::class),
		$this->createMock(IndexRepository::class),
	);
});

afterEach(function (): void {
	removeRecursively($this->tmpRoot);
});

it('returns the collection when directory name matches the meta id', function (): void {
	writeMeta($this->tmpRoot . '/blog', ['id' => 'blog', 'schema' => 'blog']);

	$collection = $this->repo->fetchCollection('blog');

	expect($collection)->not->toBeNull();
	expect($collection->id)->toBe('blog');
});

it('returns null when the meta id does not match the directory name', function (): void {
	// `blog-bkp/.meta.json` claims id="blog" — a manual backup of the real
	// `blog/` collection. The mismatch makes this stale state invisible.
	writeMeta($this->tmpRoot . '/blog-bkp', ['id' => 'blog', 'schema' => 'blog']);

	expect($this->repo->fetchCollection('blog-bkp'))->toBeNull();
});

it('skips stale backup directories so listAllCollections has no duplicate ids', function (): void {
	// Two directories, same id. The legitimate one wins; the backup is dropped.
	writeMeta($this->tmpRoot . '/dataviews', ['id' => 'dataviews', 'schema' => 'dataviews']);
	writeMeta($this->tmpRoot . '/dataviews-bkp', ['id' => 'dataviews', 'schema' => 'dataviews']);

	$ids = array_map(static fn ($c): string => $c->id, $this->repo->listAllCollections());

	expect($ids)->toBe(['dataviews']);
});

/**
 * @param array<string,mixed> $meta
 */
function writeMeta(string $dir, array $meta): void
{
	if (!is_dir($dir)) {
		mkdir($dir, 0755, true);
	}
	file_put_contents($dir . '/.meta.json', (string)json_encode($meta));
}

function removeRecursively(string $path): void
{
	if (!is_dir($path)) {
		if (is_file($path) || is_link($path)) {
			@unlink($path);
		}

		return;
	}

	$entries = scandir($path);
	if ($entries === false) {
		return;
	}

	foreach ($entries as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$child = $path . '/' . $entry;
		if (is_dir($child) && !is_link($child)) {
			removeRecursively($child);
		} else {
			@unlink($child);
		}
	}

	@rmdir($path);
}
