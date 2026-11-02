<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Service\ImageSourceResolver;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

// The `/{prop}/{path}` ImageWorks URL serves images nested in cards, decks
// and videos as well as galleries. These pin which image each path finds and
// the subpath its file lives under. Gallery picks are in ImageGeneratorTest.

function sourceResolverFor(mixed $property): ImageSourceResolver
{
	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn($property);

	return new ImageSourceResolver($fetcher);
}

test('a card child image is found by its key and lives under it', function (): void {
	[$image, $subpath] = sourceResolverFor(new CardData(['photo' => ['name' => 'face.jpg'], 'title' => 'Hi']))
		->pathImage('team', 'jo', 'profile', 'photo');

	expect($image->name)->toBe('face.jpg')
		->and($subpath)->toBe('photo');
});

test('a deck child image is found by item id and key', function (): void {
	$deck = new DeckData(['item_3' => ['photo' => ['name' => 'three.jpg']]]);

	[$image, $subpath] = sourceResolverFor($deck)->pathImage('team', 'jo', 'slides', 'item_3/photo');

	expect($image->name)->toBe('three.jpg')
		->and($subpath)->toBe('item_3/photo');
});

test('a video serves its poster and nothing else', function (): void {
	$video = new VideoData(['url' => 'https://example.test/v', 'poster' => ['name' => 'poster.jpg']]);

	[$image] = sourceResolverFor($video)->pathImage('films', 'f1', 'clip', 'poster');
	expect($image->name)->toBe('poster.jpg');

	expect(fn () => sourceResolverFor($video)->pathImage('films', 'f1', 'clip', 'url'))
		->toThrow(UnexpectedValueException::class);
});

test('a missing child or one without a file is not found', function (): void {
	$card = new CardData(['photo' => ['name' => ''], 'title' => 'Hi']);

	expect(fn () => sourceResolverFor($card)->pathImage('team', 'jo', 'profile', 'missing'))
		->toThrow(UnexpectedValueException::class, "Nested image 'missing' not found under 'profile'")
		->and(fn () => sourceResolverFor($card)->pathImage('team', 'jo', 'profile', 'photo'))
		->toThrow(UnexpectedValueException::class, "Nested image 'photo' has no file");
});

test('image() takes only an image property', function (): void {
	expect(sourceResolverFor(new ImageData(['name' => 'a.jpg']))->image('blog', 'p1', 'image')->name)->toBe('a.jpg')
		->and(fn () => sourceResolverFor(new GalleryData())->image('blog', 'p1', 'title'))
		->toThrow(UnexpectedValueException::class, 'Invalid image property found');
});
