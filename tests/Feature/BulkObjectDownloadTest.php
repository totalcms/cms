<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Export\Service\ObjectZipper;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$container        = $this->app->getContainer();
	$collection       = new CollectionData();
	$collection->id   = 'posts';
	$collection->name = 'Posts';
	$collection->schema = 'blog';
	$container->get(CollectionSaver::class)->saveCollection($collection->toArray());

	$saver = $container->get(ObjectSaver::class);
	foreach (['a', 'b', 'c'] as $id) {
		$saver->saveObject('posts', [
			'id'      => $id,
			'title'   => 'Post ' . $id,
			'created' => '2026-06-08T00:00:00+00:00',
			'updated' => '2026-06-08T00:00:00+00:00',
		]);
	}

	$this->zipper = $container->get(ObjectZipper::class);
});

/** @return list<string> */
function zipEntryNames(string $path): array
{
	$zip = new ZipArchive();
	$zip->open($path);
	$names = [];
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$names[] = $zip->getNameIndex($i);
	}
	$zip->close();

	return $names;
}

it('zips only the selected objects', function (): void {
	$path = $this->zipper->createObjectsZip('posts', ['a', 'c']);
	expect(file_exists($path))->toBeTrue();

	$names = zipEntryNames($path);
	@unlink($path);

	expect($names)->toContain('a.json');
	expect($names)->toContain('c.json');
	expect($names)->not->toContain('b.json');
});

it('skips missing ids and still zips the rest', function (): void {
	$path  = $this->zipper->createObjectsZip('posts', ['a', 'ghost']);
	$names = zipEntryNames($path);
	@unlink($path);

	expect($names)->toContain('a.json');
	expect($names)->not->toContain('ghost.json');
});

it('throws when none of the ids resolve to an object', function (): void {
	expect(fn () => $this->zipper->createObjectsZip('posts', ['ghost1', 'ghost2']))
		->toThrow(RuntimeException::class);
});
