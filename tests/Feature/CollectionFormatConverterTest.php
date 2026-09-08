<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionFormatConverter;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'posts', 'name' => 'Posts', 'schema' => 'blog']);
	$saver = $c->get(ObjectSaver::class);
	foreach (['one', 'two', 'three'] as $id) {
		$saver->saveObject('posts', ['id' => $id, 'title' => ucfirst($id), 'content' => "<p>{$id}</p>"]);
	}
	$this->converter = $c->get(CollectionFormatConverter::class);
	$this->fetcher   = $c->get(ObjectFetcher::class);
	$this->files     = fn (string $ext): array => array_map('basename', glob(collectionPath('posts') . '*.' . $ext) ?: []);
});

it('converts json to markdown and back, deleting the old files and flipping the setting', function (): void {
	$report = $this->converter->convert('posts', 'markdown');
	expect($report->converted)->toBe(3)->and($report->failed)->toBe([]);
	expect(($this->files)('md'))->toHaveCount(3)->and(($this->files)('json'))->toBe([]);
	expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('posts')->format)->toBe('markdown');
	expect($this->fetcher->fetchObjectFromDisk('posts', 'two')->toArray()['content'])->toBe('<p>two</p>');

	$this->converter->convert('posts', 'json');
	expect(($this->files)('json'))->toHaveCount(3)->and(($this->files)('md'))->toBe([]);
	expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('posts')->format)->toBe('json');
});

it('dry run writes nothing', function (): void {
	$report = $this->converter->convert('posts', 'markdown', dryRun: true);
	expect($report->dryRun)->toBeTrue()->and($report->converted)->toBe(3);
	expect(($this->files)('md'))->toBe([])->and(($this->files)('json'))->toHaveCount(3);
	expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('posts')->format)->toBe('json');
});

it('is a no-op when already in that format and rejects an unknown one', function (): void {
	expect($this->converter->convert('posts', 'json')->converted)->toBe(0);
	expect(fn () => $this->converter->convert('posts', 'toml'))->toThrow(DomainException::class);
	expect(fn () => $this->converter->convert('nope', 'markdown'))->toThrow(UnexpectedValueException::class);
});

it('reports an object it could not read, converts the rest, and leaves the collection readable', function (): void {
	file_put_contents(collectionPath('posts') . 'broken.json', '{not json');

	$report = $this->converter->convert('posts', 'markdown');
	expect($report->converted)->toBe(3)->and($report->failed)->toBe(['broken' => 'read']);
	expect(file_exists(collectionPath('posts') . 'broken.json'))->toBeTrue(); // left alone
	expect($this->fetcher->fetchObjectFromDisk('posts', 'one')->toArray()['title'])->toBe('One');
});

it('resumes an interrupted conversion by re-running, converting only what is left', function (): void {
	$this->converter->convert('posts', 'markdown');

	// Simulate a run that died after flipping the format but before 'two'
	// was rewritten: put 'two' back into the old (json) shape by hand, using
	// its real (full) data so it still passes schema validation on save.
	$original = $this->fetcher->fetchObjectFromDisk('posts', 'two')->toArray();
	file_put_contents(collectionPath('posts') . 'two.json', (string)json_encode($original));
	unlink(collectionPath('posts') . 'two.md');

	$report = $this->converter->convert('posts', 'markdown');
	expect($report->converted)->toBe(1)->and($report->skipped)->toBe(2)->and($report->failed)->toBe([]);
	expect(file_exists(collectionPath('posts') . 'two.md'))->toBeTrue();
	expect(file_exists(collectionPath('posts') . 'two.json'))->toBeFalse();
	expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('posts')->format)->toBe('markdown');

	// Nothing left to do now — every object already reads back as markdown.
	$report = $this->converter->convert('posts', 'markdown');
	expect($report->converted)->toBe(0)->and($report->skipped)->toBe(3);
});
