<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\ImageWorks\Data\WarmVariants;
use TotalCMS\Domain\ImageWorks\Service\ImageWorksWarmer;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

/**
 * Images inside a card or deck are named `parent/child`: `mycard/photo` is the
 * card's photo, `mydeck/photo` the photo of every deck item, and a video child
 * warms its poster. As in ImageWorksWarmerTest, "warmed" is pinned to the
 * route's own cache key by counting the `.cache` files either side of a route
 * visit.
 *
 * Fixture: w1 has a card photo, a poster on the card's video, two deck items
 * with a photo each, a poster on deck item one's video, and a top-level
 * video `clip` with a poster.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c       = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);
	$schemas->saveSchema(['id' => 'warm-card', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'label' => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
		'promo' => ['type' => 'video', 'field' => 'video', 'settings' => ['poster' => ['extractPalette' => false]]],
	]]);
	$ref = 'https://www.totalcms.co/schemas/custom/warm-card.json';
	$schemas->saveSchema(['id' => 'warm-widgets', 'type' => 'object', 'properties' => [
		'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'  => ['type' => 'string', 'field' => 'text'],
		'mycard' => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => $ref],
		'mydeck' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => $ref],
		'clip'   => ['type' => 'video', 'field' => 'video', 'settings' => ['poster' => ['extractPalette' => false]]],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'widgets', 'name' => 'Widgets', 'schema' => 'warm-widgets']);
	$c->get(ObjectSaver::class)->saveObject('widgets', [
		'id'     => 'w1',
		'title'  => 'Widget',
		'clip'   => ['url' => 'https://vimeo.com/987654321'],
		'mycard' => ['label' => 'Card label', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']],
		'mydeck' => [
			'one' => ['id' => 'one', 'label' => 'One', 'promo' => ['url' => 'https://vimeo.com/123456789']],
			'two' => ['id' => 'two', 'label' => 'Two'],
		],
	]);

	expect(postUpload('/api/collections/widgets/w1/mycard/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/widgets/w1/mydeck/one/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/widgets/w1/mydeck/two/photo', testData('image-exif.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/widgets/w1/mydeck/one/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/widgets/w1/clip/poster', testData('test-image.jpg'), 'image/jpeg', 'poster')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/widgets/w1/mycard/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster')->getStatusCode())->toBe(200);

	$this->warmer   = fn (): ImageWorksWarmer => $this->app->getContainer()->get(ImageWorksWarmer::class);
	$this->variants = WarmVariants::fromOptions([], [], false, ['w=100'], [], [], null);

	// Every file in any `.cache` folder under the object (Glide nests them).
	$this->cacheFiles = function (): int {
		$count = 0;
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(objectFilesPath('widgets', 'w1'), FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$count += $file->isFile() && str_contains($file->getPathname(), '/.cache/') ? 1 : 0;
		}

		return $count;
	};
});

test('a card image is warmed under the key the route serves', function (): void {
	$report = ($this->warmer)()->warm('widgets', 'mycard/photo', $this->variants);
	expect($report->images)->toBe(1)->and($report->made)->toBe(1)->and($report->failed)->toBe(0);

	$before = ($this->cacheFiles)();
	expect(get('/imageworks/widgets/w1/mycard/photo.jpg?w=100')->getStatusCode())->toBe(200);
	expect(($this->cacheFiles)())->toBe($before);
});

test('a deck image is warmed in every item', function (): void {
	$report = ($this->warmer)()->warm('widgets', 'mydeck/photo', $this->variants);
	expect($report->images)->toBe(2)->and($report->made)->toBe(2)->and($report->failed)->toBe(0);

	$before = ($this->cacheFiles)();
	expect(get('/imageworks/widgets/w1/mydeck/one/photo.jpg?w=100')->getStatusCode())->toBe(200)
		->and(get('/imageworks/widgets/w1/mydeck/two/photo.jpg?w=100')->getStatusCode())->toBe(200);
	expect(($this->cacheFiles)())->toBe($before);
});

test('a file the route made first is reported as cached', function (): void {
	expect(get('/imageworks/widgets/w1/mydeck/two/photo.jpg?w=100')->getStatusCode())->toBe(200);

	$report = ($this->warmer)()->warm('widgets', 'mydeck/photo', $this->variants);
	expect($report->cached)->toBe(1)->and($report->made)->toBe(1);
});

test('a video child warms its poster, and items without one are skipped', function (): void {
	$report = ($this->warmer)()->warm('widgets', 'mydeck/promo', $this->variants);
	expect($report->images)->toBe(1)->and($report->made)->toBe(1)->and($report->failed)->toBe(0);

	$before = ($this->cacheFiles)();
	expect(get('/imageworks/widgets/w1/mydeck/one/promo/poster.jpg?w=100')->getStatusCode())->toBe(200);
	expect(($this->cacheFiles)())->toBe($before);
});

test('a card or deck alone is an error that lists its images', function (): void {
	($this->warmer)()->properties('widgets', 'mycard');
})->throws(InvalidArgumentException::class, 'mycard is a card. Name the image inside it as parent/child: mycard/photo, mycard/promo.');

test('a child that is not an image or video is an error', function (): void {
	($this->warmer)()->properties('widgets', 'mycard/label');
})->throws(InvalidArgumentException::class, 'label is not an image or video field in mycard.');

test('a deeper path is an error', function (): void {
	($this->warmer)()->properties('widgets', 'mydeck/one/photo');
})->throws(InvalidArgumentException::class, 'is not a valid name');

test('a plain run warms no videos and does not reach into cards and decks', function (): void {
	($this->warmer)()->properties('widgets', null);
})->throws(InvalidArgumentException::class, 'Collection widgets has no image or gallery properties.');

test('a card video child warms its poster', function (): void {
	$report = ($this->warmer)()->warm('widgets', 'mycard/promo', $this->variants);
	expect($report->images)->toBe(1)->and($report->made)->toBe(1)->and($report->failed)->toBe(0);

	$before = ($this->cacheFiles)();
	expect(get('/imageworks/widgets/w1/mycard/promo/poster.jpg?w=100')->getStatusCode())->toBe(200);
	expect(($this->cacheFiles)())->toBe($before);
});

test('a video takes only its poster as a child', function (): void {
	($this->warmer)()->properties('widgets', 'clip/other');
})->throws(InvalidArgumentException::class, 'clip is a video. Name it as clip or clip/poster to warm its poster.');

test('a card schema that cannot be read is named in the error', function (): void {
	$this->app->getContainer()->get(SchemaSaver::class)->saveSchema(['id' => 'warm-broken', 'type' => 'object', 'properties' => [
		'id'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'gone' => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => 'https://www.totalcms.co/schemas/custom/no-such-card.json'],
	]]);
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'broken', 'name' => 'Broken', 'schema' => 'warm-broken']);

	($this->warmer)()->properties('broken', 'gone/photo');
})->throws(InvalidArgumentException::class, 'gone uses the schema no-such-card, which could not be read.');

test('a deckTable with the legacy deckref is a deck', function (): void {
	$this->app->getContainer()->get(SchemaSaver::class)->saveSchema(['id' => 'warm-legacy', 'type' => 'object', 'properties' => [
		'id'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'rows' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deckTable', 'deckref' => 'https://www.totalcms.co/schemas/custom/warm-card.json'],
	]]);
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'legacy', 'name' => 'Legacy', 'schema' => 'warm-legacy']);

	expect(($this->warmer)()->properties('legacy', 'rows/photo'))
		->toBe(['rows/photo' => ['root' => 'rows', 'field' => 'deck', 'child' => 'photo', 'childField' => 'image']]);
});

test('a watermark on the deck refuses its images under an edition without watermarks', function (): void {
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'warm-widgets', 'type' => 'object', 'properties' => [
		'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'  => ['type' => 'string', 'field' => 'text'],
		'mydeck' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => 'https://www.totalcms.co/schemas/custom/warm-card.json', 'settings' => ['watermark' => ['marktext' => 'Sample']]],
	], 'index' => ['id', 'title']]);
	$editions = $this->createStub(EditionFeatureService::class);
	$editions->method('can')->willReturn(false);
	$c->set(EditionFeatureService::class, $editions);

	$warmer = ($this->warmer)();
	expect($warmer->blockedWatermark('widgets', $warmer->properties('widgets', 'mydeck/photo')))->toBe('mydeck/photo');
});

test('a top-level video warms its poster, named alone or as video/poster', function (string $name): void {
	$report = ($this->warmer)()->warm('widgets', $name, $this->variants);
	expect($report->images)->toBe(1)->and($report->made)->toBe(1)->and($report->failed)->toBe(0);

	$before = ($this->cacheFiles)();
	expect(get('/imageworks/widgets/w1/clip/poster.jpg?w=100')->getStatusCode())->toBe(200);
	expect(($this->cacheFiles)())->toBe($before);
})->with(['clip', 'clip/poster']);

test('a nested failure names where the image lives', function (): void {
	unlink(objectFilesPath('widgets', 'w1') . '/mydeck/two/photo/image-exif.jpg');

	$report = ($this->warmer)()->warm('widgets', 'mydeck/photo', $this->variants);
	expect($report->failed)->toBe(1)
		->and($report->failures[0]['property'])->toBe('mydeck/two/photo');
});
