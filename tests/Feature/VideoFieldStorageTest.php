<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\postUpload;
use function TotalCMS\Slim\Pest\get;

/**
 * The `video` property type stores as a plain object whose key set is owned by
 * `VideoData` — no card, no sub-schema, no card `id`. This proves the whole
 * chain end to end: schema registration, PropertyFactory dispatch to VideoData
 * through the generic type path, and the nested-upload machinery resolving the
 * one fixed `poster` child without consulting a schemaref.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'clips', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'promo' => ['type' => 'video', 'field' => 'video', 'label' => 'Promo'],
	], 'index' => ['id', 'title', 'promo']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'clips', 'name' => 'Clips', 'schema' => 'clips']);
	$this->saver   = $c->get(ObjectSaver::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
});

it('stores a video as a plain object with the derived keys and no card id', function (): void {
	$this->saver->saveObject('clips', ['id' => 'one', 'title' => 'One', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']]);
	$promo = $this->fetcher->fetchObjectFromDisk('clips', 'one')->toArray()['promo'];
	expect($promo)->toMatchArray(['url' => 'https://youtu.be/abc123XYZ_-'])
		->and(array_keys($promo))->toContain('provider', 'videoId', 'thumbnail', 'title', 'aspectRatio')
		// The card `id` key existed only because the card mechanism forced it.
		->and($promo)->not->toHaveKey('id')
		// No poster was uploaded, so the key is simply absent.
		->and($promo)->not->toHaveKey('poster');
	expect($this->fetcher->fetchObject('clips', 'one')->properties->get('promo'))->toBeInstanceOf(VideoData::class);
});

it('does not register a video-embed schema', function (): void {
	expect(SchemaData::RESERVED_SCHEMAS)->not->toContain('video-embed');
	expect(fn () => $this->app->getContainer()->get(SchemaFetcher::class)->fetchSchema('video-embed'))->toThrow(Exception::class);
});

it('resolves a nested poster upload path without any sub-schema', function (): void {
	$this->saver->saveObject('clips', ['id' => 'one', 'title' => 'One', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']]);
	$saver = $this->app->getContainer()->get(SaverFactory::class)->generateSaverService('clips', 'promo', 'one', 'poster');
	expect($saver)->toBeInstanceOf(ImageSaver::class);
});

it('rejects any nested child of a video other than poster', function (): void {
	$this->saver->saveObject('clips', ['id' => 'one', 'title' => 'One', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']]);
	$factory = $this->app->getContainer()->get(SaverFactory::class);
	expect(fn () => $factory->generateSaverService('clips', 'promo', 'one', 'artwork'))
		->toThrow(UnexpectedValueException::class);
});

it('serves the uploaded poster through the nested ImageWorks route', function (): void {
	$this->saver->saveObject('clips', ['id' => 'one', 'title' => 'One', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']]);
	postUpload('/api/collections/clips/one/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');

	// The admin preview, cms.media.videoPoster() and the poster's own field
	// thumbnail all build `/imageworks/{coll}/{id}/{property}/poster.{fmt}`.
	// ImageGenerator dispatches that nested path on the parent's class, so a
	// VideoData parent must be recognised alongside CardData/DeckData or the
	// URL 404s and every poster in the admin shows as broken (found live).
	$response = get('/imageworks/clips/one/promo/poster.jpg?w=50');

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('Content-Type'))->toStartWith('image/');

	// Anything other than `poster` under a video is not an image.
	expect(get('/imageworks/clips/one/promo/thumbnail.jpg')->getStatusCode())->toBeIn([400, 404]);
});

it('uploads a poster through the nested collection-property route and writes it back onto the object', function (): void {
	$this->saver->saveObject('clips', ['id' => 'one', 'title' => 'One', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']]);

	// The real end-to-end route for a card (and, per this test, video) child
	// upload is FileSaveAction at POST /api/collections/{collection}/{id}/
	// {property}/{path} — {path} is the child key ("poster"), not a filename.
	// This is the route the JS Droplet actually posts to (FileSaveAction,
	// TotalField.buildPropertyApi()). The generic /api/upload/... media-library
	// route only writes raw storage via PropertyRepository::saveFile() and
	// never touches the object, so it cannot be the route that sets
	// `promo.poster.name` — see FileSaver::fetchExistingChildProperty()'s
	// VideoData branch, which reads the existing child off `$parent->poster`.
	$response = postUpload('/api/collections/clips/one/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');

	expect($response->getStatusCode())->toBe(200);

	$promo = $this->fetcher->fetchObjectFromDisk('clips', 'one')->toArray()['promo'];
	expect($promo['poster']['name'] ?? '')->not->toBe('');

	$posterDir = objectFilesPath('clips', 'one') . '/promo/poster';
	expect(is_dir($posterDir))->toBeTrue();
	expect(glob($posterDir . '/*'))->not->toBeEmpty();
});
