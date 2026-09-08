<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;

/**
 * cms.render.video() / cms.media.videoPoster() — Twig rendering for the
 * `video` field (docs/planning/3.5.x/video-field.md, "Twig"). Objects are
 * passed directly as arrays (the same "pass object directly" idiom
 * cms.render.image() supports), so no schema/collection/disk setup is
 * needed — these are pure functions of the stored video shape.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c            = $this->app->getContainer();
	$this->render = $c->get(RenderTwigAdapter::class);
	$this->media  = $c->get(MediaTwigAdapter::class);
});

function videoObject(array $video): array
{
	return ['id' => 'one', 'promo' => array_merge([
		'id'          => 'promo',
		'url'         => '',
		'provider'    => '',
		'videoId'     => '',
		'thumbnail'   => '',
		'title'       => '',
		'aspectRatio' => '',
		'poster'      => ['name' => '', 'size' => 0],
	], $video)];
}

$providerCases = [
	'youtube'    => ['https://www.youtube.com/watch?v=abc123XYZ_-', 'https://www.youtube-nocookie.com/embed/abc123XYZ_-'],
	'vimeo'      => ['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789'],
	'livid'      => ['https://livid.com/watch/xyz789', 'https://livid.com/embed/xyz789'],
	'bunny'      => ['https://iframe.mediadelivery.net/play/12345/aaaa-bbbb', 'https://iframe.mediadelivery.net/embed/12345/aaaa-bbbb'],
	'cloudflare' => ['https://customer-abc.cloudflarestream.com/vid123/watch', 'https://customer-abc.cloudflarestream.com/vid123/iframe'],
	'loom'       => ['https://www.loom.com/share/abcdef', 'https://www.loom.com/embed/abcdef'],
	'wistia'     => ['https://acme.wistia.com/medias/abc123', 'https://fast.wistia.net/embed/iframe/abc123'],
	'publitio'   => ['https://media.weaversspace.com/file/weaversspace/play/weaversspace-intro-z.html?player=wsplayer', 'https://media.weaversspace.com/file/weaversspace/play/weaversspace-intro-z.html?player=wsplayer'],
];

test('each iframe provider renders an eager iframe with the embed url, title, and wrapper class', function (string $url, string $embedUrl): void {
	$object = videoObject(['url' => $url, 'title' => 'My Great Video']);

	$html = $this->render->video($object, ['property' => 'promo']);

	expect($html)->toContain('class="cms-video-embed"')
		->and($html)->toContain('<iframe')
		->and($html)->toContain('src="' . $embedUrl . '"')
		->and($html)->toContain('title="My Great Video"')
		->and($html)->toContain('loading="lazy"')
		->and($html)->toContain('allowfullscreen')
		->and($html)->toContain('referrerpolicy="strict-origin-when-cross-origin"');
})->with($providerCases);

test('the stored aspect ratio is emitted as the --cms-video-ratio custom property, not an inline aspect-ratio', function (): void {
	$object = videoObject(['url' => 'https://vimeo.com/123456789', 'aspectRatio' => '4:3']);

	$html = $this->render->video($object, ['property' => 'promo']);

	// The stylesheet reads the variable with a 16 / 9 fallback, so a site rule
	// can change the shape without `!important`. A direct inline
	// `aspect-ratio` would beat every stylesheet rule.
	expect($html)->toContain('style="--cms-video-ratio: 4 / 3"')
		->and($html)->not->toContain('aspect-ratio:');
});

test('an empty or malformed stored aspect ratio falls back to 16 / 9 in the custom property', function (): void {
	$object = videoObject(['url' => 'https://example.com/some/page', 'aspectRatio' => 'wide']);

	$html = $this->render->video($object, ['property' => 'promo']);

	expect($html)->toContain('style="--cms-video-ratio: 16 / 9"');
});

test('unknown provider falls back to EmbedBuilder::iframe() output inside the wrapper', function (): void {
	$object = videoObject(['url' => 'https://example.com/some/page', 'title' => 'Random Page']);

	$html = $this->render->video($object, ['property' => 'promo']);

	expect($html)->toContain('class="cms-video-embed"')
		->and($html)->toContain('data-src="https://example.com/some/page"')
		->and($html)->toContain('cms-iframe');
});

// Final review fix (Critical #1): a stored `url` that isn't http(s) resolves
// to provider 'unknown' with an empty embedUrl — nothing safe to embed. Both
// the eager and facade branches must render nothing rather than build an
// iframe/video around it. The file-provider branch is unreachable for such
// URLs after VideoUrlResolver's guard, so it's asserted here too rather than
// assumed.
test('a non-http(s) stored url renders nothing (eager)', function (): void {
	$object = videoObject(['url' => 'javascript:alert(document.domain)', 'title' => 'XSS']);

	expect($this->render->video($object, ['property' => 'promo']))->toBe('');
});

test('a non-http(s) stored url renders nothing (facade: true)', function (): void {
	$object = videoObject([
		'url'       => 'javascript:alert(document.domain)',
		'title'     => 'XSS',
		'thumbnail' => 'https://example.com/thumb.jpg',
	]);

	expect($this->render->video($object, ['property' => 'promo', 'facade' => true]))->toBe('');
});

test('a non-http(s) stored url never reaches the file/video branch either', function (): void {
	// Not reachable in practice (VideoUrlResolver never returns provider
	// 'file' for a non-http(s) URL), asserted defensively.
	$object = videoObject(['url' => 'javascript:alert(1).mp4']);

	expect($this->render->video($object, ['property' => 'promo']))->toBe('')
		->and($this->render->video($object, ['property' => 'promo', 'facade' => true]))->toBe('');
});

test('the file provider renders a <video> element with poster and wrapper', function (): void {
	$object = videoObject([
		'url'       => 'https://cdn.example.com/clip.mp4',
		'thumbnail' => 'https://cdn.example.com/thumb.jpg',
	]);

	$html = $this->render->video($object, ['property' => 'promo']);

	expect($html)->toContain('class="cms-video-embed"')
		->and($html)->toContain('<video')
		->and($html)->toContain('src="https://cdn.example.com/clip.mp4"')
		->and($html)->toContain('playsinline')
		->and($html)->toContain('controls')
		->and($html)->toContain('poster="https://cdn.example.com/thumb.jpg"');
});

test('a file-field value (video/* mime) renders <video src> via cms.media.stream()', function (): void {
	$object = [
		'id'   => 'one',
		'clip' => [
			'name'       => 'intro.mp4',
			'size'       => 1024,
			'mime'       => 'video/mp4',
			'uploadDate' => '2026-01-01',
		],
	];

	$html = $this->render->video($object, ['property' => 'clip', 'collection' => 'clips']);

	expect($html)->toContain('<video')
		->and($html)->toContain('src="')
		->and($html)->toContain('/stream/clips/one/clip');
});

test('the facade is the default whenever a poster or thumbnail resolves', function (): void {
	$object = videoObject(['url' => 'https://vimeo.com/123456789', 'thumbnail' => 'https://cdn.example.com/thumb.jpg', 'title' => 'Clip']);

	$html = $this->render->video($object, ['property' => 'promo']);

	expect($html)->toContain('class="cms-video-facade"')
		->and($html)->toContain('src="https://cdn.example.com/thumb.jpg"')
		->and($html)->not->toContain('<iframe');
});

test('facade: false forces the eager iframe even when a thumbnail resolves', function (): void {
	$object = videoObject(['url' => 'https://vimeo.com/123456789', 'thumbnail' => 'https://cdn.example.com/thumb.jpg']);

	$html = $this->render->video($object, ['property' => 'promo', 'facade' => false]);

	expect($html)->toContain('class="cms-video-embed"')
		->and($html)->toContain('<iframe')
		->and($html)->not->toContain('cms-video-facade');
});

test('facade: true renders a poster + play button with data-embed carrying autoplay=1', function (): void {
	$object = videoObject([
		'url'       => 'https://vimeo.com/123456789',
		'title'     => 'Facade Video',
		'thumbnail' => 'https://vimeo.example/thumb.jpg',
	]);

	$html = $this->render->video($object, ['property' => 'promo', 'facade' => true]);

	expect($html)->toContain('class="cms-video-facade"')
		->and($html)->toContain('data-embed="https://player.vimeo.com/video/123456789?autoplay=1"')
		->and($html)->toContain('<img')
		->and($html)->toContain('src="https://vimeo.example/thumb.jpg"')
		->and($html)->toContain('alt="Facade Video"')
		->and($html)->toContain('<button type="button" aria-label="Play Facade Video">');
});

test('unknown provider + facade: true keeps data-embed as the raw stored url, unmutated', function (): void {
	$object = videoObject([
		'url'       => 'https://example.com/some/page?a=1&b=2',
		'title'     => 'Random Page',
		'thumbnail' => 'https://example.com/thumb.jpg',
	]);

	$html = $this->render->video($object, ['property' => 'promo', 'facade' => true]);

	expect($html)->toContain('class="cms-video-facade"')
		->and($html)->toContain('data-embed="https://example.com/some/page?a=1&amp;b=2"')
		->and($html)->not->toContain('autoplay');
});

test('facade: true with no resolvable poster falls back to the eager embed instead of an empty facade', function (): void {
	$object = videoObject(['url' => 'https://example.com/some/page', 'title' => 'No Thumbnail']);

	$html = $this->render->video($object, ['property' => 'promo', 'facade' => true]);

	expect($html)->toContain('class="cms-video-embed"')
		->and($html)->not->toContain('cms-video-facade')
		->and($html)->toContain('data-src="https://example.com/some/page"');
});

test('the imageworks option is applied to an uploaded poster in facade mode', function (): void {
	$object = videoObject([
		'url'       => 'https://vimeo.com/123456789',
		'thumbnail' => 'https://cdn.example.com/thumb.jpg',
		'poster'    => ['name' => 'poster.jpg', 'size' => 500, 'hash' => 'abc123'],
	]);

	$html = $this->render->video($object, ['property' => 'promo', 'facade' => true, 'imageworks' => ['w' => 1200, 'fm' => 'webp']]);

	expect($html)->toContain('class="cms-video-facade"')
		->and($html)->toContain('/imageworks/')
		->and($html)->toContain('promo/poster')
		->and($html)->toContain('w=1200')
		// ImageWorks encodes the format as the URL extension, not a query key.
		->and($html)->toContain('promo/poster.webp?');
});

test('the imageworks option is applied to the <video> poster for the file provider', function (): void {
	$object = videoObject([
		'url'    => 'https://cdn.example.com/clip.mp4',
		'poster' => ['name' => 'poster.jpg', 'size' => 500, 'hash' => 'abc123'],
	]);

	$html = $this->render->video($object, ['property' => 'promo', 'imageworks' => ['w' => 800]]);

	expect($html)->toContain('<video')
		->and($html)->toContain('promo/poster')
		->and($html)->toContain('w=800');
});

test('the imageworks option never touches a vendor thumbnail', function (): void {
	$object = videoObject(['url' => 'https://vimeo.com/123456789', 'thumbnail' => 'https://cdn.example.com/thumb.jpg']);

	$html = $this->render->video($object, ['property' => 'promo', 'facade' => true, 'imageworks' => ['w' => 1200]]);

	expect($html)->toContain('src="https://cdn.example.com/thumb.jpg"')
		->and($html)->not->toContain('w=1200');
});

test('the poster option overrides the resolved poster (uploaded or vendor thumbnail)', function (): void {
	$object = videoObject([
		'url'       => 'https://cdn.example.com/clip.mp4',
		'thumbnail' => 'https://vendor.example/thumb.jpg',
		'poster'    => ['name' => 'poster.jpg', 'size' => 500, 'hash' => 'abc123'],
	]);

	$html = $this->render->video($object, ['property' => 'promo', 'poster' => 'https://override.example/custom-poster.jpg']);

	expect($html)->toContain('poster="https://override.example/custom-poster.jpg"')
		->and($html)->not->toContain('vendor.example')
		->and($html)->not->toContain('imageworks');
});

test('loop and muted render as boolean attributes on the <video> element', function (): void {
	$object = videoObject(['url' => 'https://cdn.example.com/clip.mp4']);

	$html = $this->render->video($object, ['property' => 'promo', 'loop' => true, 'muted' => true]);

	expect($html)->toContain('<video')
		->and($html)->toContain('loop')
		->and($html)->toContain('muted');
});

test('autoplay: true builds the right query for YouTube (autoplay=1)', function (): void {
	$object = videoObject(['url' => 'https://www.youtube.com/watch?v=abc123XYZ_-']);

	$html = $this->render->video($object, ['property' => 'promo', 'autoplay' => true]);

	expect($html)->toContain('src="https://www.youtube-nocookie.com/embed/abc123XYZ_-?autoplay=1"');
});

test('autoplay: true builds the right query for Vimeo (autoplay=1)', function (): void {
	$object = videoObject(['url' => 'https://vimeo.com/123456789']);

	$html = $this->render->video($object, ['property' => 'promo', 'autoplay' => true]);

	expect($html)->toContain('src="https://player.vimeo.com/video/123456789?autoplay=1"');
});

test('an empty video property returns an empty string', function (): void {
	$object = videoObject(['url' => '']);

	expect($this->render->video($object, ['property' => 'promo']))->toBe('');
});

test('a missing video property returns an empty string', function (): void {
	expect($this->render->video(['id' => 'one'], ['property' => 'promo']))->toBe('');
});

test('cms.render.video() returns an empty string for a null/empty idOrObject', function (): void {
	expect($this->render->video(null))->toBe('');
	expect($this->render->video(''))->toBe('');
});

test('videoPoster() prefers the uploaded poster over the vendor thumbnail', function (): void {
	$object = videoObject([
		'thumbnail' => 'https://vendor.example/thumb.jpg',
		'poster'    => ['name' => 'poster.jpg', 'size' => 500, 'hash' => 'abc123'],
	]);

	$url = $this->media->videoPoster($object, [], ['property' => 'promo']);

	expect($url)->toContain('/imageworks/')
		->and($url)->toContain('promo/poster');
});

test('videoPoster() falls back to the vendor thumbnail when there is no uploaded poster', function (): void {
	$object = videoObject(['thumbnail' => 'https://vendor.example/thumb.jpg']);

	expect($this->media->videoPoster($object, [], ['property' => 'promo']))->toBe('https://vendor.example/thumb.jpg');
});

test('videoPoster() returns an empty string when neither poster nor thumbnail exist', function (): void {
	$object = videoObject([]);

	expect($this->media->videoPoster($object, [], ['property' => 'promo']))->toBe('');
});
