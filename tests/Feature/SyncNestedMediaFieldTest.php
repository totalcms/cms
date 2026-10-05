<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

// Regression: the "binaries never travel" rule stopped at the top level.
//
// A Site Builder page has no image field of its own — its one image is
// `seo.image`, inside the `seo` card. The exporter stripped only top-level
// media fields, so a page push carried the card's image reference (file name,
// alt text) to a site that did not have the file. The importer preserved only
// top-level media too, so re-uploading the image on the destination lasted
// until the next push replaced the card.
//
// See SyncMediaFieldTest.php for the top-level half of the same rule.

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c      = $this->app->getContainer();
	$schema = $c->get(SchemaSaver::class);

	$schema->saveSchema([
		'id'         => 'mediapart',
		'name'       => 'Media Part',
		'type'       => 'object',
		'properties' => [
			'id'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'caption' => ['type' => 'string', 'field' => 'text'],
			'image'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
		],
	]);

	$ref = 'https://www.totalcms.co/schemas/custom/mediapart.json';
	$schema->saveSchema([
		'id'         => 'nestedmediatest',
		'name'       => 'Nested Media Test',
		'type'       => 'object',
		'properties' => [
			'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'title'  => ['type' => 'string', 'field' => 'text'],
			'seo'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => $ref],
			'slides' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => $ref],
		],
	]);

	// exportSyncData only walks SyncableCollections::IDS.
	$col         = new CollectionData();
	$col->id     = 'builder-pages';
	$col->name   = 'Pages';
	$col->schema = 'nestedmediatest';
	$c->get(CollectionSaver::class)->saveCollection($col->toArray());

	$this->importer = $c->get(JumpStartImporter::class);
	$this->exporter = $c->get(JumpStartExporter::class);
	$this->fetcher  = $c->get(ObjectFetcher::class);
	$this->saver    = $c->get(ObjectSaver::class);
});

it('omits image fields inside cards and decks from the sync payload', function (): void {
	$this->saver->saveObject('builder-pages', [
		'id'     => 'home',
		'title'  => 'Home',
		'seo'    => ['caption' => 'Share card', 'image' => ['name' => 'og.jpg', 'size' => 1234, 'alt' => 'Social']],
		'slides' => [
			'one' => ['id' => 'one', 'caption' => 'First', 'image' => ['name' => 'one.jpg', 'size' => 10, 'alt' => 'One']],
		],
	]);

	$data = $this->exporter->exportSyncData(null, null, ['builder-pages' => null]);

	$exported = null;
	foreach ($data->objects as $object) {
		if (($object['id'] ?? '') === 'home') {
			$exported = $object['data'];
		}
	}

	expect($exported)->not->toBeNull();
	// The rest of the card and the deck item still travel.
	expect($exported['seo']['caption'])->toBe('Share card');
	expect($exported['slides']['one']['caption'])->toBe('First');
	// The references do not: the destination has no og.jpg or one.jpg.
	expect($exported['seo'])->not->toHaveKey('image');
	expect($exported['slides']['one'])->not->toHaveKey('image');
});

it('keeps the destination images inside cards and decks when a sync payload omits them', function (): void {
	$this->saver->saveObject('builder-pages', [
		'id'     => 'home',
		'title'  => 'Original',
		'seo'    => ['caption' => 'Old card', 'image' => ['name' => 'destination.jpg', 'size' => 999, 'alt' => 'Destination art']],
		'slides' => [
			'one' => ['id' => 'one', 'caption' => 'Old first', 'image' => ['name' => 'slide.jpg', 'size' => 10, 'alt' => 'Slide']],
		],
	]);

	$this->importer->importFromDefinition([
		'objects' => [
			[
				'collection' => 'builder-pages',
				'id'         => 'home',
				'data'       => [
					'id'     => 'home',
					'title'  => 'Updated',
					'seo'    => ['caption' => 'New card'],
					'slides' => [
						'one' => ['id' => 'one', 'caption' => 'New first'],
						'two' => ['id' => 'two', 'caption' => 'Brand new'],
					],
				],
			],
		],
	], upsert: true);

	$object = $this->fetcher->fetchObject('builder-pages', 'home')->toArray();

	// Authored fields are updated…
	expect($object['title'])->toBe('Updated');
	expect($object['seo']['caption'])->toBe('New card');
	expect($object['slides']['one']['caption'])->toBe('New first');
	expect($object['slides']['two']['caption'])->toBe('Brand new');
	// …and the media the destination owns survives the push.
	expect($object['seo']['image']['name'])->toBe('destination.jpg');
	expect($object['seo']['image']['alt'])->toBe('Destination art');
	expect($object['slides']['one']['image']['name'])->toBe('slide.jpg');
	// A deck item new to the destination has no image to keep, and gets none.
	expect($object['slides']['two']['image']['name'] ?? '')->toBe('');
});
