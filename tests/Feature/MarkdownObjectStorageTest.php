<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use function TotalCMS\Slim\Pest\get;

/**
 * A markdown collection stores {id}.md and nothing above the repository
 * notices. Reads resolve by existence so a half-converted collection and a
 * hand-dropped file both load.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'docs', 'name' => 'Docs', 'schema' => 'blog', 'format' => 'markdown']);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'posts', 'name' => 'Posts', 'schema' => 'blog']);
	$this->saver   = $c->get(ObjectSaver::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
	$this->repo    = $c->get(ObjectRepository::class);
	$this->post    = ['id' => 'hello', 'title' => 'Hello', 'draft' => true, 'tags' => ['a', 'b'], 'content' => "<p>Body</p>"];
});

it('writes {id}.md with frontmatter and the content body', function (): void {
	$this->saver->saveObject('docs', $this->post);

	$file = collectionPath('docs') . 'hello.md';
	expect(file_exists($file))->toBeTrue()->and(file_exists(collectionPath('docs') . 'hello.json'))->toBeFalse();
	$raw = (string)file_get_contents($file);
	expect($raw)->toStartWith("---\n")->toContain("title: Hello")->toContain("draft: true")->toEndWith("---\n\n<p>Body</p>\n");
	expect($raw)->not->toContain('content:');
});

it('reads back the same object, typed by the schema', function (): void {
	$this->saver->saveObject('docs', $this->post);
	$this->app->getContainer()->get(\TotalCMS\Domain\Cache\CacheManager::class)->clearAllCaches();

	$object = $this->fetcher->fetchObject('docs', 'hello')->toArray();
	expect($object['title'])->toBe('Hello')->and($object['draft'])->toBeTrue()->and($object['tags'])->toBe(['a', 'b'])->and($object['content'])->toBe('<p>Body</p>');
	expect($this->fetcher->fetchObjectFromDisk('docs', 'hello')->toArray()['content'])->toBe('<p>Body</p>');
});

it('answers the API identically for both formats', function (): void {
	$this->saver->saveObject('docs', $this->post);
	$this->saver->saveObject('posts', $this->post);

	$md   = json_decode((string)get('/api/collections/docs/hello')->assertOk()->getBody(), true);
	$json = json_decode((string)get('/api/collections/posts/hello')->assertOk()->getBody(), true);
	unset($md['created'], $md['updated'], $json['created'], $json['updated']);
	expect($md)->toEqual($json);
});

it('resolves a read by existence in both directions and prefers the collection format', function (): void {
	// A .json dropped into a markdown collection still loads …
	file_put_contents(collectionPath('docs') . 'stray.json', json_encode(['id' => 'stray', 'title' => 'Stray JSON']));
	expect($this->fetcher->fetchObjectFromDisk('docs', 'stray')->toArray()['title'])->toBe('Stray JSON');
	expect($this->repo->objectPath('docs', 'stray'))->toBe('docs/stray.json');

	// … and a .md dropped into a JSON collection.
	file_put_contents(collectionPath('posts') . 'hand.md', "---\nid: hand\ntitle: Hand written\n---\n\nBody\n");
	expect($this->fetcher->fetchObjectFromDisk('posts', 'hand')->toArray()['title'])->toBe('Hand written');

	// Both present: the collection's format wins.
	file_put_contents(collectionPath('docs') . 'both.json', json_encode(['id' => 'both', 'title' => 'JSON']));
	file_put_contents(collectionPath('docs') . 'both.md', "---\nid: both\ntitle: Markdown\n---\n\n\n");
	expect($this->fetcher->fetchObjectFromDisk('docs', 'both')->toArray()['title'])->toBe('Markdown');
	expect($this->repo->objectPath('docs', 'both'))->toBe('docs/both.md');
	expect($this->repo->objectPath('docs', 'missing'))->toBeNull();
});

it('deletes whichever files the object has', function (): void {
	$this->saver->saveObject('docs', $this->post);
	file_put_contents(collectionPath('docs') . 'hello.json', '{"id":"hello"}');

	$this->app->getContainer()->get(ObjectRemover::class)->deleteObject('docs', 'hello');
	expect(file_exists(collectionPath('docs') . 'hello.md'))->toBeFalse()->and(file_exists(collectionPath('docs') . 'hello.json'))->toBeFalse();
});

it('keeps json collections byte-identical to before', function (): void {
	$this->saver->saveObject('posts', $this->post);
	$raw = (string)file_get_contents(collectionPath('posts') . 'hello.json');
	expect($raw)->toBe((string)json_encode(json_decode($raw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

it('does not keep a stale format when the collection is converted mid-request', function (): void {
	$this->saver->saveObject('posts', $this->post);
	expect(file_exists(collectionPath('posts') . 'hello.json'))->toBeTrue();

	$c                  = $this->app->getContainer();
	$collectionFetcher  = $c->get(CollectionFetcher::class);
	$collection         = $collectionFetcher->fetchCollection('posts');
	expect($collection)->toBeInstanceOf(CollectionData::class);
	$collection->format = CollectionData::FORMAT_MARKDOWN;
	$c->get(CollectionRepository::class)->saveCollection($collection);
	$collectionFetcher->clearCache('posts');

	$this->saver->saveObject('posts', ['id' => 'second', 'title' => 'Second', 'draft' => false, 'tags' => [], 'content' => 'Body']);

	expect(file_exists(collectionPath('posts') . 'second.md'))->toBeTrue()
		->and(file_exists(collectionPath('posts') . 'second.json'))->toBeFalse()
		->and(file_exists(collectionPath('posts') . 'hello.json'))->toBeTrue();
	expect($this->fetcher->fetchObjectFromDisk('posts', 'hello')->toArray()['title'])->toBe('Hello');
	expect($this->fetcher->fetchObjectFromDisk('posts', 'second')->toArray()['title'])->toBe('Second');
});

it('aborts a save rather than truncating the file when the object cannot be encoded (malformed UTF-8)', function (): void {
	$this->saver->saveObject('posts', $this->post);
	$original = (string)file_get_contents(collectionPath('posts') . 'hello.json');

	// Base the malformed object on the one just saved (carries the required
	// date fields ObjectSaver stamps) rather than building from scratch —
	// only the title is what makes this save fail.
	$data          = $this->fetcher->fetchObjectFromDisk('posts', 'hello')->toArray();
	$data['title'] = "\xB1\x31";
	$bad           = $this->app->getContainer()->get(\TotalCMS\Domain\Object\Service\ObjectFactory::class)
		->generateObject('posts', $data);

	// Whichever layer catches it first (schema validation re-encodes to JSON
	// too, and fails the same way) — the point is that SOMETHING throws
	// before a single byte is written, so the file on disk is never touched.
	expect(fn () => $this->repo->saveObject('posts', $bad))->toThrow(\Exception::class);
	expect((string)file_get_contents(collectionPath('posts') . 'hello.json'))->toBe($original);
});

it('skips an unparseable object file instead of throwing', function (): void {
	file_put_contents(collectionPath('docs') . 'broken.md', "---\nid: [\n---\n\nBody\n");
	expect($this->repo->fetchObjectFromDisk('docs', 'broken'))->toBeNull();
});

it('indexes both extensions once each and skips dot files and an unparseable md', function (): void {
	$this->saver->saveObject('docs', $this->post);
	file_put_contents(collectionPath('docs') . 'hello.json', json_encode(['id' => 'hello', 'title' => 'stale']));
	file_put_contents(collectionPath('docs') . 'broken.md', "---\nid: [\n---\n");
	file_put_contents(collectionPath('docs') . '.hidden.md', "---\nid: hidden\n---\n");

	$ids = $this->app->getContainer()->get(\TotalCMS\Domain\Index\Repository\IndexRepository::class)->fetchObjectIdsFromDisk('docs');
	sort($ids);
	expect($ids)->toBe(['broken', 'hello']);

	$indexBuilder = $this->app->getContainer()->get(\TotalCMS\Domain\Index\Service\IndexBuilder::class);
	$index        = $indexBuilder->buildIndex('docs');
	expect($index->objects->pluck('id')->all())->toBe(['hello']); // broken skipped, logged, build succeeds
	expect($indexBuilder->lastSkippedIds())->toBe(['broken']);
});

it('a plain buildIndex() does not evict a warmed object — callers on the ordinary save path must not pay for this', function (): void {
	// smartBuildIndex() calls buildIndex() directly on every save when
	// queueRebuildOnSave is off, so IndexBuilder itself must NOT clear the
	// per-object cache — that would evict the whole collection's warmed
	// objects on every single save. Only an explicit rebuild entry point
	// (tcms repair:index, the admin/API rebuild action) does that, via
	// ObjectFetcher::clearCollectionCache() — see the next test.
	$this->saver->saveObject('docs', $this->post);
	expect($this->fetcher->fetchObject('docs', 'hello')->toArray()['title'])->toBe('Hello');

	file_put_contents(
		collectionPath('docs') . 'hello.md',
		"---\nid: hello\ntitle: Edited By Hand\ndraft: true\ntags:\n  - a\n  - b\n---\n\n<p>Body</p>\n",
	);

	$this->app->getContainer()->get(\TotalCMS\Domain\Index\Service\IndexBuilder::class)->buildIndex('docs');

	// Still the warmed (stale) value — buildIndex() alone doesn't touch the cache.
	expect($this->fetcher->fetchObject('docs', 'hello')->toArray()['title'])->toBe('Hello');
});

it('ObjectFetcher::clearCollectionCache() after a rebuild is what makes a hand-edit re-servable (what repair:index and the rebuild action do)', function (): void {
	$this->saver->saveObject('docs', $this->post);
	// Warm the per-object cache with the ORIGINAL title.
	expect($this->fetcher->fetchObject('docs', 'hello')->toArray()['title'])->toBe('Hello');

	// A hand edit outside Total CMS, bypassing saveObject() entirely.
	file_put_contents(
		collectionPath('docs') . 'hello.md',
		"---\nid: hello\ntitle: Edited By Hand\ndraft: true\ntags:\n  - a\n  - b\n---\n\n<p>Body</p>\n",
	);

	$this->app->getContainer()->get(\TotalCMS\Domain\Index\Service\IndexBuilder::class)->buildIndex('docs');
	// This is the step RepairIndexCommand and IndexBuildAction each take
	// right after buildIndex() — buildIndex() itself no longer does it.
	$this->fetcher->clearCollectionCache('docs');

	expect($this->fetcher->fetchObject('docs', 'hello')->toArray()['title'])->toBe('Edited By Hand');
});

it('zips and backs up the markdown file', function (): void {
	$this->saver->saveObject('docs', $this->post);

	$zipPath = $this->app->getContainer()->get(\TotalCMS\Domain\Export\Service\ObjectZipper::class)->createObjectZip('docs', 'hello');
	$zip = new ZipArchive();
	$zip->open($zipPath);
	expect($zip->locateName('hello.md'))->not->toBeFalse()->and($zip->locateName('hello.json'))->toBeFalse();
	$zip->close();
	unlink($zipPath);
});

it('always identifies an object by its file name, not an id: line in hand-edited frontmatter', function (): void {
	// No `id:` at all …
	file_put_contents(collectionPath('docs') . 'hand.md', "---\ntitle: Hand\n---\n\nBody\n");
	// … and an `id:` that disagrees with the filename. Either way the
	// filename wins: it's the object's actual identity on disk.
	file_put_contents(collectionPath('docs') . 'other.md', "---\nid: something-else\ntitle: Other\n---\n\nBody\n");

	expect($this->fetcher->fetchObjectFromDisk('docs', 'hand')->id)->toBe('hand');
	expect($this->fetcher->fetchObjectFromDisk('docs', 'other')->id)->toBe('other');

	$index = $this->app->getContainer()->get(\TotalCMS\Domain\Index\Service\IndexBuilder::class)->buildIndex('docs');
	$ids   = $index->objects->pluck('id')->all();
	sort($ids);
	expect($ids)->toBe(['hand', 'other']);
});

it('keeps a numeric filename id a string through disk enumeration and index build', function (): void {
	file_put_contents(collectionPath('docs') . '1.md', "---\nid: '1'\ntitle: One\n---\n\nBody\n");
	file_put_contents(collectionPath('docs') . '2.json', json_encode(['id' => '2', 'title' => 'Two']));

	$ids = $this->app->getContainer()->get(\TotalCMS\Domain\Index\Repository\IndexRepository::class)->fetchObjectIdsFromDisk('docs');
	sort($ids);
	expect($ids)->toBe(['1', '2']);
	expect(array_map('gettype', $ids))->toBe(['string', 'string']);

	$index = $this->app->getContainer()->get(\TotalCMS\Domain\Index\Service\IndexBuilder::class)->buildIndex('docs');
	$entry = $index->objects->first(fn (array $o): bool => $o['id'] === '1');
	expect($entry)->not->toBeNull();
	expect($entry['id'])->toBeString();
});

it('the API index rebuild refreshes cached objects for a markdown collection but leaves a json collection alone', function (): void {
	foreach (['docs' => 'hello.md', 'posts' => 'hello.json'] as $collection => $file) {
		$this->saver->saveObject($collection, $this->post);
		expect($this->fetcher->fetchObject($collection, 'hello')->toArray()['title'])->toBe('Hello'); // warm the caches

		$path = collectionPath($collection) . $file;
		$raw  = (string)file_get_contents($path);
		file_put_contents($path, str_replace('Hello', 'Edited by hand', $raw));

		\TotalCMS\Slim\Pest\put('/api/collections/' . $collection . '/index')->assertOk();
	}

	expect($this->fetcher->fetchObject('docs', 'hello')->toArray()['title'])->toBe('Edited by hand');
	expect($this->fetcher->fetchObject('posts', 'hello')->toArray()['title'])->toBe('Hello');
});
