<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Service\CollectionFileRepairService;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

/**
 * A `video` property inside a card or a deck item works like a top-level one:
 * the URL is resolved on save (the card/deck records its children's field
 * types so the save pipeline can find the video), the poster uploads through
 * the multi-segment nested route (`hero/promo/poster`, `slides/one/promo/poster`),
 * ImageWorks serves it back through the same path, and repair knows the path.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);
	$schemas->saveSchema(['id' => 'hero-card', 'type' => 'object', 'properties' => [
		'id'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'heading' => ['type' => 'string', 'field' => 'text'],
		'promo'   => ['type' => 'video', 'field' => 'video', 'label' => 'Promo'],
	]]);
	$schemas->saveSchema(['id' => 'reels', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'hero'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => 'https://www.totalcms.co/schemas/custom/hero-card.json'],
		'slides' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => 'https://www.totalcms.co/schemas/custom/hero-card.json'],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'reels', 'name' => 'Reels', 'schema' => 'reels']);
	$this->saver   = $c->get(ObjectSaver::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
});

function reelObject(): array
{
	return [
		'id'     => 'one',
		'title'  => 'One',
		'hero'   => ['heading' => 'Hero', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']],
		'slides' => ['first' => ['id' => 'first', 'heading' => 'First', 'promo' => ['url' => 'https://vimeo.com/123456789']]],
	];
}

it('resolves the provider of a video inside a card and inside a deck item on save', function (): void {
	$this->saver->saveObject('reels', reelObject());

	$stored = $this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray();

	expect($stored['hero']['promo'])->toMatchArray(['provider' => 'youtube', 'videoId' => 'abc123XYZ_-'])
		->and($stored['hero']['promo']['thumbnail'])->toContain('img.youtube.com/vi/abc123XYZ_-')
		->and($stored['slides']['first']['promo'])->toMatchArray(['provider' => 'vimeo', 'videoId' => '123456789']);

	// The card knows what its children are — that is how the save pipeline found the video.
	$hero = $this->fetcher->fetchObject('reels', 'one')->properties->get('hero');
	expect($hero)->toBeInstanceOf(CardData::class)
		->and($hero->childTypes)->toMatchArray(['promo' => 'video', 'heading' => 'string']);
});

it('accepts a bare URL string for a nested video, as CSV import and API clients send', function (): void {
	$object = reelObject();
	$object['hero']['promo'] = 'https://youtu.be/abc123XYZ_-';
	$this->saver->saveObject('reels', $object);

	$promo = $this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray()['hero']['promo'];
	expect($promo)->toMatchArray(['url' => 'https://youtu.be/abc123XYZ_-', 'provider' => 'youtube']);
});

it('resolves the poster upload path through a card and through a deck item to the image saver', function (): void {
	$this->saver->saveObject('reels', reelObject());
	$factory = $this->app->getContainer()->get(SaverFactory::class);

	expect($factory->generateSaverService('reels', 'hero', 'one', 'promo/poster'))->toBeInstanceOf(ImageSaver::class)
		->and($factory->generateSaverService('reels', 'slides', 'one', 'first/promo/poster'))->toBeInstanceOf(ImageSaver::class);

	expect(fn () => $factory->generateSaverService('reels', 'hero', 'one', 'promo/thumbnail'))->toThrow(UnexpectedValueException::class);
});

it('uploads a poster for a video inside a card and serves it back through ImageWorks', function (): void {
	$this->saver->saveObject('reels', reelObject());

	$response = postUpload('/api/collections/reels/one/hero/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');
	expect($response->getStatusCode())->toBe(200);

	$stored = $this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray();
	expect($stored['hero']['promo']['poster']['name'] ?? '')->not->toBe('')
		// Siblings survive the nested write.
		->and($stored['hero']['heading'])->toBe('Hero')
		->and($stored['hero']['promo']['provider'])->toBe('youtube');
	expect(is_dir(objectFilesPath('reels', 'one') . '/hero/promo/poster'))->toBeTrue();

	$image = get('/imageworks/reels/one/hero/promo/poster.jpg?w=50');
	expect($image->getStatusCode())->toBe(200)
		->and($image->getHeaderLine('Content-Type'))->toStartWith('image/');
});

it('uploads a poster for a video inside a deck item and serves it back through ImageWorks', function (): void {
	$this->saver->saveObject('reels', reelObject());

	$response = postUpload('/api/collections/reels/one/slides/first/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');
	expect($response->getStatusCode())->toBe(200);

	$stored = $this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray();
	expect($stored['slides']['first']['promo']['poster']['name'] ?? '')->not->toBe('')
		->and($stored['slides']['first']['heading'])->toBe('First');
	expect(is_dir(objectFilesPath('reels', 'one') . '/slides/first/promo/poster'))->toBeTrue();

	$image = get('/imageworks/reels/one/slides/first/promo/poster.jpg?w=50');
	expect($image->getStatusCode())->toBe(200);
});

it('repair:files finds a nested video poster whose data was blanked out of the object', function (): void {
	$this->saver->saveObject('reels', reelObject());
	postUpload('/api/collections/reels/one/hero/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');

	// Simulate a PUT that dropped the poster: write the object back without it.
	$object = $this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray();
	unset($object['hero']['promo']['poster']);
	$this->app->getContainer()->get(ObjectUpdater::class)->updateObject('reels', 'one', $object);
	expect($this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray()['hero']['promo'])->not->toHaveKey('poster');

	$repair = $this->app->getContainer()->get(CollectionFileRepairService::class);
	$report = $repair->apply('reels', new RepairFilters());

	$candidates = array_values(array_filter($report->candidates, fn ($c) => ($c->subpath ?? '') === 'promo/poster'));
	expect($candidates)->toHaveCount(1)
		->and($candidates[0]->applied)->toBeTrue();
	expect($this->fetcher->fetchObjectFromDisk('reels', 'one')->toArray()['hero']['promo']['poster']['name'] ?? '')->not->toBe('');
});

it('exports a video as one column holding just the URL, at the top level and inside a card', function (): void {
	$this->saver->saveObject('reels', reelObject());

	$export  = $this->app->getContainer()->get(ObjectExporter::class)->exportAllObjectsForCSv('reels');
	$headers = $export['data'][0];
	$row     = array_combine($headers, $export['data'][1]);

	expect($headers)->toContain('hero.promo')
		->and($headers)->not->toContain('hero.promo.url')
		->and($row['hero.promo'])->toBe('https://youtu.be/abc123XYZ_-');
});
