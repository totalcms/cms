<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Factory\Faker\FakerPicsum;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

// Regression: sync used to FABRICATE images on the destination.
//
// The exporter wrote the literal string "image" as an image field's value —
// meant as a type marker, but identical to the factory-rule syntax the importer
// honors. So every push made the receiving site generate a random placeholder
// image, silently, every time. Clearing an image at the source could never
// propagate either, because the payload had no way to express "empty".
//
// JumpStart carries no binaries, so an image field is not syncable data in
// either direction: the source cannot send one (the file would not exist there)
// and must not clear one (it has no authority over media it never received).
// The destination owns these fields; images are managed in the admin.

beforeEach(function (): void {
	// The `image` factory rule downloads a placeholder from picsum.photos. Stub
	// the HTTP client so this file never depends on a third-party service being
	// reachable — picsum returning 503 made the factory-rule test below fail,
	// and a 20s timeout made every run slower. FakerPicsum::setHttpClient()
	// exists for exactly this (see tests/Unit/Domain/Factory/FakerPicsumTest.php).
	//
	// A real JPEG, not filler bytes: the imported value is saved as an image
	// property and goes through mime detection.
	$image = imagecreatetruecolor(16, 16);
	ob_start();
	imagejpeg($image);
	$jpeg = (string) ob_get_clean();
	imagedestroy($image);

	$httpClient = test()->createMock(HttpClientInterface::class);
	$httpClient->method('request')->willReturn(new HttpResponse(200, $jpeg));
	FakerPicsum::setHttpClient($httpClient);

	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();

	$c->get(SchemaSaver::class)->saveSchema([
		'id'         => 'mediatest',
		'name'       => 'Media Test',
		'type'       => 'object',
		'properties' => [
			'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'title' => ['type' => 'string', 'field' => 'text'],
			'image' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
		],
	]);

	// `builder-pages` is the allowlisted sync collection this bug was found on,
	// and exportSyncData only walks SyncableCollections::IDS.
	$col         = new CollectionData();
	$col->id     = 'builder-pages';
	$col->name   = 'Pages';
	$col->schema = 'mediatest';
	$c->get(CollectionSaver::class)->saveCollection($col->toArray());

	$this->importer = $c->get(JumpStartImporter::class);
	$this->exporter = $c->get(JumpStartExporter::class);
	$this->fetcher  = $c->get(ObjectFetcher::class);
	$this->saver    = $c->get(ObjectSaver::class);
});

afterEach(function (): void {
	FakerPicsum::setHttpClient(null);
});

it('omits image fields from the sync payload instead of writing a type marker', function (): void {
	$this->saver->saveObject('builder-pages', [
		'id'    => 'home',
		'title' => 'Home',
		'image' => ['name' => 'banner.jpg', 'size' => 1234, 'alt' => 'Banner'],
	]);

	$data = $this->exporter->exportSyncData(null, null, ['builder-pages' => null]);

	$exported = null;
	foreach ($data->objects as $object) {
		if (($object['id'] ?? '') === 'home') {
			$exported = $object['data'];
		}
	}

	expect($exported)->not->toBeNull();
	// The marker is the bug: "image" here is read back as a factory rule.
	expect($exported)->not->toHaveKey('image');
	expect($exported['title'])->toBe('Home');
});

it('keeps the destination image when a sync payload omits it', function (): void {
	$this->saver->saveObject('builder-pages', [
		'id'    => 'home',
		'title' => 'Original Title',
		'image' => ['name' => 'destination.jpg', 'size' => 999, 'alt' => 'Destination art'],
	]);

	// A sync payload: real fields travel, the image key is absent.
	$this->importer->importFromDefinition([
		'objects' => [
			[
				'collection' => 'builder-pages',
				'id'         => 'home',
				'data'       => ['id' => 'home', 'title' => 'Updated Title'],
			],
		],
	], upsert: true);

	$object = $this->fetcher->fetchObject('builder-pages', 'home')->toArray();

	expect($object['title'])->toBe('Updated Title');
	expect($object['image']['name'])->toBe('destination.jpg');
	expect($object['image']['alt'])->toBe('Destination art');
});

it('does not fabricate an image on a destination that has none', function (): void {
	$this->saver->saveObject('builder-pages', [
		'id'    => 'blank',
		'title' => 'Blank',
	]);

	$this->importer->importFromDefinition([
		'objects' => [
			[
				'collection' => 'builder-pages',
				'id'         => 'blank',
				'data'       => ['id' => 'blank', 'title' => 'Still Blank'],
			],
		],
	], upsert: true);

	$object = $this->fetcher->fetchObject('builder-pages', 'blank')->toArray();

	expect($object['title'])->toBe('Still Blank');
	expect($object['image']['name'] ?? '')->toBe('');
});

it('still honors an image factory rule an author wrote by hand', function (): void {
	// Starter kits deliberately seed demo content with generated images. That
	// is a value the author put in the file, not something the exporter emits,
	// so it must keep working — it is the reason the marker was ambiguous.
	$this->importer->importFromDefinition([
		'objects' => [
			[
				'collection' => 'builder-pages',
				'id'         => 'seeded',
				'data'       => ['id' => 'seeded', 'title' => 'Seeded', 'image' => 'image'],
			],
		],
	]);

	$object = $this->fetcher->fetchObject('builder-pages', 'seeded')->toArray();

	expect($object['title'])->toBe('Seeded');
	expect($object['image']['name'] ?? '')->not->toBe('');
});
