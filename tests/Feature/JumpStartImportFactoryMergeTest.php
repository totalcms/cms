<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

// Regression: importing REAL objects through JumpStart must not faker-fill
// schema properties missing from the import data. The collection's factory
// definitions (explicit `factory:` rules + auto-derived boolean rules) are
// test-data generators — merging them into plain object imports randomized
// toggles (e.g. builder-page `sitemap`) and generated placeholder images.
// Factory entries (`factory:` blocks) keep the merge — that's their purpose.

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();

	$c->get(SchemaSaver::class)->saveSchema([
		'id'         => 'seedtest',
		'name'       => 'Seed Test',
		'type'       => 'object',
		'properties' => [
			'id'          => ['type' => 'string', 'field' => 'id'],
			'title'       => ['type' => 'string', 'field' => 'text'],
			// Explicit factory rule — must NOT run for imported objects.
			'description' => ['type' => 'string', 'field' => 'text', 'factory' => 'sentence'],
			// Toggle — auto-derives `boolean`; must NOT be randomized on import.
			'sitemap'     => ['type' => 'boolean', 'field' => 'toggle', 'default' => true],
		],
	]);

	$col         = new CollectionData();
	$col->id     = 'seeds';
	$col->name   = 'Seeds';
	$col->schema = 'seedtest';
	$c->get(CollectionSaver::class)->saveCollection($col->toArray());

	$this->importer = $c->get(JumpStartImporter::class);
	$this->fetcher  = $c->get(ObjectFetcher::class);
});

it('does not faker-fill schema properties missing from imported object data', function (): void {
	$result = $this->importer->importFromDefinition([
		'objects' => [
			[
				'collection' => 'seeds',
				'id'         => 'real-page',
				'data'       => ['id' => 'real-page', 'title' => 'Real Page'],
			],
		],
	]);

	expect($result->success)->toBeTrue();

	$object = $this->fetcher->fetchObject('seeds', 'real-page')->toArray();

	expect($object['title'])->toBe('Real Page');
	// `description` has a schema factory rule; an imported object that omits it
	// must not receive a faker sentence.
	expect($object['description'] ?? '')->toBe('');
	// `sitemap` is a toggle; absent from the import it must land on the schema
	// default, never a coin flip.
	expect($object['sitemap'] ?? null)->not->toBeFalse();
});

it('still honors faker image rules written into imported object data', function (): void {
	// Import-side faker support is intentionally limited to image/gallery rules
	// the author wrote — a non-image rule in the data is kept as literal text.
	$this->importer->importFromDefinition([
		'objects' => [
			[
				'collection' => 'seeds',
				'id'         => 'literal-rule',
				'data'       => ['id' => 'literal-rule', 'title' => 'sentence'],
			],
		],
	]);

	$object = $this->fetcher->fetchObject('seeds', 'literal-rule')->toArray();
	expect($object['title'])->toBe('sentence');
});

it('still merges collection factory definitions for factory entries', function (): void {
	$result = $this->importer->importFromDefinition([
		'factory' => [
			[
				'collection' => 'seeds',
				'id'         => 'fake-page',
				'data'       => ['title' => 'word'],
			],
		],
	]);

	expect($result->success)->toBeTrue();

	$object = $this->fetcher->fetchObject('seeds', 'fake-page')->toArray();

	// Factory path keeps schema-declared rules: description gets a sentence.
	expect($object['description'] ?? '')->not->toBe('');
});
