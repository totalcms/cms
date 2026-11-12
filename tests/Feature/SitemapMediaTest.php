<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;

// The images and videos a collection's sitemap settings name, listed under
// each object's <url> (SitemapMedia).

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$container = $this->app->getContainer();
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$this->sitemap = function (string $collection, array $settings) use ($container): void {
		$container->get(CollectionSaver::class)->patchCollection($collection, ['url' => "/{$collection}/{id}", 'sitemap' => ['enabled' => true] + $settings]);
	};
	$this->save = fn (string $collection, array $object) => $container->get(ObjectSaver::class)->saveObject($collection, $object);
	$this->xml  = function (string $collection): string {
		$response = $this->app->handle($this->createRequest('GET', "/sitemap/{$collection}"));
		expect($response->getStatusCode())->toBe(200);

		return (string)$response->getBody();
	};
	$this->image = fn (string $name): array => ['name' => $name, 'size' => 100, 'width' => 2000, 'height' => 1000, 'mime' => 'image/jpeg', 'uploadDate' => '2026-11-11 10:00:00'];
});

it('lists no images until the collection names a property', function (): void {
	($this->sitemap)('blog', []);
	($this->save)('blog', ['id' => 'hello', 'title' => 'Hello', 'image' => ($this->image)('hero.jpg')]);

	expect(($this->xml)('blog'))->toContain('<loc>')
		->not->toContain('image:image')
		->not->toContain('xmlns:image');
});

it('lists an image and every gallery image through ImageWorks', function (): void {
	($this->sitemap)('blog', ['images' => ['image', 'gallery']]);
	($this->save)('blog', [
		'id'      => 'hello',
		'title'   => 'Hello',
		'image'   => ($this->image)('hero.jpg'),
		'gallery' => [($this->image)('one.jpg'), ($this->image)('two.jpg')],
	]);

	$xml = ($this->xml)('blog');
	expect($xml)->toContain('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"')
		->and(substr_count($xml, '<image:image>'))->toBe(3)
		->and($xml)->toMatch('#<image:loc>https?://[^<]+/imageworks/blog/hello/image\.jpg\?w=1600[^<]*</image:loc>#')
		->and($xml)->toMatch('#<image:loc>https?://[^<]+/imageworks/blog/hello/gallery/one\.jpg\?w=1600[^<]*</image:loc>#')
		->and($xml)->toMatch('#<image:loc>https?://[^<]+/imageworks/blog/hello/gallery/two\.jpg\?w=1600[^<]*</image:loc>#');
});

it('skips an empty image property', function (): void {
	($this->sitemap)('blog', ['images' => ['image', 'gallery']]);
	($this->save)('blog', ['id' => 'hello', 'title' => 'Hello']);

	expect(($this->xml)('blog'))->toContain('/blog/hello</loc>')->not->toContain('<image:image>');
});

it('lists a hosted video with its player, thumbnail, title and description', function (): void {
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'clips', 'name' => 'Clips', 'schema' => 'video']);
	($this->sitemap)('clips', ['videos' => ['video']]);
	($this->save)('clips', ['id' => 'roast', 'video' => [
		'url'       => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
		'provider'  => 'youtube',
		'videoId'   => 'dQw4w9WgXcQ',
		'thumbnail' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
		'title'     => 'Roasting day',
	]]);

	$xml = ($this->xml)('clips');
	expect($xml)->toContain('xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"')
		->toContain('<video:player_loc>https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ</video:player_loc>')
		->toContain('<video:thumbnail_loc>https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg</video:thumbnail_loc>')
		->toContain('<video:title>Roasting day</video:title>')
		->toMatch('#<video:description>[^<]+</video:description>#')
		->not->toContain('<video:content_loc>');
});

it('skips a video with no thumbnail', function (): void {
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'clips', 'name' => 'Clips', 'schema' => 'video']);
	($this->sitemap)('clips', ['videos' => ['video']]);
	($this->save)('clips', ['id' => 'raw', 'video' => ['url' => 'https://example.com/media/raw.mp4']]);

	expect(($this->xml)('clips'))->toContain('/clips/raw</loc>')->not->toContain('<video:video>');
});

it('lists a video file as content, with its uploaded poster', function (): void {
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'clips', 'name' => 'Clips', 'schema' => 'video']);
	($this->sitemap)('clips', ['videos' => ['video']]);
	($this->save)('clips', ['id' => 'tour', 'video' => [
		'url'    => 'https://example.com/media/tour.mp4',
		'poster' => ($this->image)('poster.jpg'),
	]]);

	$xml = ($this->xml)('clips');
	expect($xml)->toContain('<video:content_loc>https://example.com/media/tour.mp4</video:content_loc>')
		->toMatch('#<video:thumbnail_loc>https?://[^<]+/imageworks/clips/tour/video/poster\.jpg\?w=1280[^<]*</video:thumbnail_loc>#')
		->not->toContain('<video:player_loc>');
});
