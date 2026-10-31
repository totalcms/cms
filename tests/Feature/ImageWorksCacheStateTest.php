<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

/**
 * `ImageGenerator::cacheState()` answers "is the file the public /imageworks
 * route would serve for these params already cached?" These tests let the real
 * route make a variant, then ask cacheState() about it — so the answer is
 * pinned to the route's own cache key, not to a re-derivation of it.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$this->config         = $c->get(Config::class);
	$this->defaultsBefore = $this->config->imageworks['defaults'] ?? null;

	$c->get(SchemaSaver::class)->saveSchema(['id' => 'snaps', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
		'pics'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery', 'settings' => ['extractPalette' => false]],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'snaps', 'name' => 'Snaps', 'schema' => 'snaps']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's1', 'title' => 'Snap']);

	expect(postUpload('/api/collections/snaps/s1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/snaps/s1/pics', testData('test-image.jpg'), 'image/jpeg', 'pics')->getStatusCode())->toBe(200);
});

afterEach(function (): void {
	$this->config->imageworks['defaults'] = $this->defaultsBefore;
});

test('a variant the route made is cached', function (): void {
	expect(get('/imageworks/snaps/s1/photo.webp?w=400')->getStatusCode())->toBe(200);

	expect($this->app->getContainer()->get(ImageGenerator::class)->cacheState('snaps', 's1', 'photo', null, ['w' => 400, 'fm' => 'webp']))
		->toBe(CacheState::Cached);
});

test('a variant nobody asked for is missing', function (): void {
	expect(get('/imageworks/snaps/s1/photo.webp?w=400')->getStatusCode())->toBe(200);

	expect($this->app->getContainer()->get(ImageGenerator::class)->cacheState('snaps', 's1', 'photo', null, ['w' => 401, 'fm' => 'webp']))
		->toBe(CacheState::Missing);
});

test('param order and a clamped width key the same', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?q=80&w=9999')->getStatusCode())->toBe(200);

	$generator = $this->app->getContainer()->get(ImageGenerator::class);
	expect($generator->cacheState('snaps', 's1', 'photo', null, ['w' => 2224, 'q' => 80, 'fm' => 'jpg']))->toBe(CacheState::Cached)
		->and($generator->cacheState('snaps', 's1', 'photo', null, ['fm' => 'jpg', 'q' => 80, 'w' => 9999]))->toBe(CacheState::Cached);
});

test('a gallery image is checked by name', function (): void {
	expect(get('/imageworks/snaps/s1/pics/test-image.webp?w=300')->getStatusCode())->toBe(200);

	// The name without its extension — what the route passes along.
	expect($this->app->getContainer()->get(ImageGenerator::class)->cacheState('snaps', 's1', 'pics', 'test-image', ['w' => 300, 'fm' => 'webp']))
		->toBe(CacheState::Cached)
		->and($this->app->getContainer()->get(ImageGenerator::class)->cacheState('snaps', 's1', 'pics', 'test-image', ['w' => 301, 'fm' => 'webp']))
		->toBe(CacheState::Missing);
});

test('the original format with no params is Original', function (): void {
	$this->config->imageworks['defaults'] = [];

	expect($this->app->getContainer()->get(ImageGenerator::class)->cacheState('snaps', 's1', 'photo', null, ['fm' => 'jpg']))
		->toBe(CacheState::Original);
});

test('both watermarks report uncacheable', function (): void {
	$c = $this->app->getContainer();

	$editions = $this->createStub(EditionFeatureService::class);
	$editions->method('can')->willReturn(true);
	$c->set(EditionFeatureService::class, $editions);

	$c->get(SchemaSaver::class)->saveSchema(['id' => 'marked', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => [
			'extractPalette' => false,
			'watermark'      => ['mark' => 'logo.png', 'marktext' => 'Sample'],
		]],
	], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'marked', 'name' => 'Marked', 'schema' => 'marked']);
	$c->get(ObjectSaver::class)->saveObject('marked', ['id' => 'm1']);
	expect(postUpload('/api/collections/marked/m1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200);

	expect($this->app->getContainer()->get(ImageGenerator::class)->cacheState('marked', 'm1', 'photo', null, ['w' => 400, 'fm' => 'webp']))
		->toBe(CacheState::Uncacheable);
});
