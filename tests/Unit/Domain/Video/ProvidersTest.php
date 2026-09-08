<?php

declare(strict_types=1);

use TotalCMS\Domain\Video\Provider\DirectFileProvider;
use TotalCMS\Domain\Video\Provider\UnknownProvider;
use TotalCMS\Domain\Video\Provider\VimeoProvider;
use TotalCMS\Domain\Video\Provider\YouTubeProvider;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

$cases = [
	// url, provider, videoId, embedUrl, thumbnail, oembedEndpoint (null = none)
	['https://www.youtube.com/watch?v=abc123XYZ_-', 'youtube', 'abc123XYZ_-', 'https://www.youtube-nocookie.com/embed/abc123XYZ_-', 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg', null],
	['https://youtu.be/abc123XYZ_-?t=10', 'youtube', 'abc123XYZ_-', 'https://www.youtube-nocookie.com/embed/abc123XYZ_-', 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg', null],
	['https://www.youtube.com/shorts/abc123XYZ_-', 'youtube', 'abc123XYZ_-', 'https://www.youtube-nocookie.com/embed/abc123XYZ_-', 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg', null],
	['https://www.youtube.com/embed/abc123XYZ_-', 'youtube', 'abc123XYZ_-', 'https://www.youtube-nocookie.com/embed/abc123XYZ_-', 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg', null],
	['https://www.youtube.com/playlist?list=PLxyz999', 'youtube', 'list:PLxyz999', 'https://www.youtube-nocookie.com/embed/videoseries?list=PLxyz999', '', null],
	['https://www.youtube.com/watch?v=abc123XYZ_-&list=PLxyz999', 'youtube', 'abc123XYZ_-', 'https://www.youtube-nocookie.com/embed/abc123XYZ_-', 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg', null],
	['https://vimeo.com/123456789', 'vimeo', '123456789', 'https://player.vimeo.com/video/123456789', '', 'https://vimeo.com/api/oembed.json?url=https%3A%2F%2Fvimeo.com%2F123456789'],
	['https://vimeo.com/123456789/abcdef0123', 'vimeo', '123456789', 'https://player.vimeo.com/video/123456789?h=abcdef0123', '', 'https://vimeo.com/api/oembed.json?url=https%3A%2F%2Fvimeo.com%2F123456789%2Fabcdef0123'],
	['https://player.vimeo.com/video/123456789', 'vimeo', '123456789', 'https://player.vimeo.com/video/123456789', '', 'https://vimeo.com/api/oembed.json?url=https%3A%2F%2Fplayer.vimeo.com%2Fvideo%2F123456789'],
	['https://livid.com/watch/xyz789', 'livid', 'xyz789', 'https://livid.com/embed/xyz789', '', 'https://livid.com/oembed?url=https%3A%2F%2Flivid.com%2Fwatch%2Fxyz789&format=json'],
	// Publitio: matched on path shape, so a custom domain (a real account: media.weaversspace.com) works exactly like media.publit.io.
	// The embed URL keeps the `?player=` choice; the thumbnail is the page's own w_1280 poster, not oEmbed's 300×200 crop; the oEmbed
	// endpoint is the slash-free form (the slashed one redirects) and is asked about the bare .html URL.
	['https://media.weaversspace.com/file/weaversspace/play/weaversspace-intro-z.html?player=wsplayer', 'publitio', 'weaversspace/play/weaversspace-intro-z', 'https://media.weaversspace.com/file/weaversspace/play/weaversspace-intro-z.html?player=wsplayer', 'https://media.weaversspace.com/file/w_1280/weaversspace/play/weaversspace-intro-z.jpg', 'https://media.publit.io/oembed?url=https%3A%2F%2Fmedia.weaversspace.com%2Ffile%2Fweaversspace%2Fplay%2Fweaversspace-intro-z.html&format=json'],
	['https://media.publit.io/file/tummy.html', 'publitio', 'tummy', 'https://media.publit.io/file/tummy.html', 'https://media.publit.io/file/w_1280/tummy.jpg', 'https://media.publit.io/oembed?url=https%3A%2F%2Fmedia.publit.io%2Ffile%2Ftummy.html&format=json'],
	['https://livid.com/video/xyz789', 'livid', 'xyz789', 'https://livid.com/embed/xyz789', '', 'https://livid.com/oembed?url=https%3A%2F%2Flivid.com%2Fvideo%2Fxyz789&format=json'],
	['https://livid.com/embed/xyz789', 'livid', 'xyz789', 'https://livid.com/embed/xyz789', '', 'https://livid.com/oembed?url=https%3A%2F%2Flivid.com%2Fembed%2Fxyz789&format=json'],
	['https://iframe.mediadelivery.net/play/12345/aaaa-bbbb', 'bunny', '12345/aaaa-bbbb', 'https://iframe.mediadelivery.net/embed/12345/aaaa-bbbb', '', 'https://video.bunnycdn.com/OEmbed?url=https%3A%2F%2Fiframe.mediadelivery.net%2Fplay%2F12345%2Faaaa-bbbb'],
	['https://iframe.mediadelivery.net/embed/12345/aaaa-bbbb', 'bunny', '12345/aaaa-bbbb', 'https://iframe.mediadelivery.net/embed/12345/aaaa-bbbb', '', 'https://video.bunnycdn.com/OEmbed?url=https%3A%2F%2Fiframe.mediadelivery.net%2Fembed%2F12345%2Faaaa-bbbb'],
	['https://customer-abc.cloudflarestream.com/vid123/watch', 'cloudflare', 'abc/vid123', 'https://customer-abc.cloudflarestream.com/vid123/iframe', 'https://customer-abc.cloudflarestream.com/vid123/thumbnails/thumbnail.jpg', null],
	['https://customer-abc.cloudflarestream.com/vid123/iframe', 'cloudflare', 'abc/vid123', 'https://customer-abc.cloudflarestream.com/vid123/iframe', 'https://customer-abc.cloudflarestream.com/vid123/thumbnails/thumbnail.jpg', null],
	['https://watch.cloudflarestream.com/vid123', 'cloudflare', 'vid123', 'https://watch.cloudflarestream.com/vid123', '', null],
	['https://www.loom.com/share/abcdef', 'loom', 'abcdef', 'https://www.loom.com/embed/abcdef', '', 'https://www.loom.com/v1/oembed?url=https%3A%2F%2Fwww.loom.com%2Fshare%2Fabcdef'],
	['https://www.loom.com/embed/abcdef', 'loom', 'abcdef', 'https://www.loom.com/embed/abcdef', '', 'https://www.loom.com/v1/oembed?url=https%3A%2F%2Fwww.loom.com%2Fembed%2Fabcdef'],
	['https://acme.wistia.com/medias/abc123', 'wistia', 'abc123', 'https://fast.wistia.net/embed/iframe/abc123', '', 'https://fast.wistia.com/oembed?url=https%3A%2F%2Facme.wistia.com%2Fmedias%2Fabc123'],
	['https://fast.wistia.net/embed/iframe/abc123', 'wistia', 'abc123', 'https://fast.wistia.net/embed/iframe/abc123', '', 'https://fast.wistia.com/oembed?url=https%3A%2F%2Ffast.wistia.net%2Fembed%2Fiframe%2Fabc123'],
	['https://cdn.example.com/clip.mp4?x=1', 'file', '', 'https://cdn.example.com/clip.mp4?x=1', '', null],
	['https://cdn.example.com/clip.WEBM', 'file', '', 'https://cdn.example.com/clip.WEBM', '', null],
	['https://cdn.example.com/clip.mov', 'file', '', 'https://cdn.example.com/clip.mov', '', null],
	['https://cdn.example.com/clip.m4v', 'file', '', 'https://cdn.example.com/clip.m4v', '', null],
	['https://cdn.example.com/clip.ogv', 'file', '', 'https://cdn.example.com/clip.ogv', '', null],
	['https://example.com/some/page', 'unknown', '', 'https://example.com/some/page', '', null],
	// Final review follow-up (Important #1): a schemeless relative/protocol-relative
	// URL is NOT an unsafe scheme — it falls through to the same catch-all a
	// random non-URL string does, embedUrl = the value itself (parity with
	// pre-video-field EmbedBuilder, which never required a scheme or host).
	['not a url', 'unknown', '', 'not a url', '', null],
	['/embeds/thing.html', 'unknown', '', '/embeds/thing.html', '', null],
	['//cdn.example.com/page', 'unknown', '', '//cdn.example.com/page', '', null],
	['/videos/clip.mp4', 'file', '', '/videos/clip.mp4', '', null],
	['//cdn.example.com/clip.webm', 'file', '', '//cdn.example.com/clip.webm', '', null],
];

test('every accepted URL form resolves to the right provider, id, embed and thumbnail', function (string $url, string $provider, string $videoId, string $embed, string $thumb, ?string $oembed): void {
	$info = (new VideoUrlResolver(VideoUrlResolver::defaultProviders()))->resolve($url);
	expect($info->provider)->toBe($provider, $url)
		->and($info->videoId)->toBe($videoId, $url)
		->and($info->embedUrl)->toBe($embed, $url)
		->and($info->thumbnail)->toBe($thumb, $url)
		->and($info->oembedEndpoint)->toBe($oembed, $url);
})->with($cases);

test('every default provider exposes a stable, non-empty id()', function (): void {
	foreach (VideoUrlResolver::defaultProviders() as $provider) {
		expect($provider->id())->toBeString()->not->toBe('');
	}
});

test('AbstractVideoProvider default embedQuery() is empty for providers that do not override it', function (): void {
	foreach (VideoUrlResolver::defaultProviders() as $provider) {
		if ($provider instanceof YouTubeProvider || $provider instanceof VimeoProvider) {
			continue;
		}
		expect($provider->embedQuery(['autoplay' => 1, 'loop' => 1, 'muted' => 1]))->toBe('');
	}
});

test('YouTubeProvider embedQuery builds autoplay/mute/loop+playlist', function (): void {
	$provider = new YouTubeProvider();

	expect($provider->embedQuery([]))->toBe('');
	expect($provider->embedQuery(['autoplay' => true]))->toBe('autoplay=1');
	expect($provider->embedQuery(['muted' => true]))->toBe('mute=1');
	expect($provider->embedQuery(['loop' => true, 'videoId' => 'abc123']))->toBe('loop=1&playlist=abc123');
});

test('VimeoProvider embedQuery builds autoplay/muted/loop/color', function (): void {
	$provider = new VimeoProvider();

	expect($provider->embedQuery([]))->toBe('');
	expect($provider->embedQuery(['autoplay' => true, 'muted' => true, 'loop' => true, 'vcolor' => 'ffffff']))
		->toBe('autoplay=1&muted=1&loop=1&color=ffffff');
});

// Final review fix (Critical #1) — belt and braces: VideoUrlResolver::resolve()
// already refuses to hand a non-http(s) URL to any provider, but these two
// providers' own matches() must independently require an http(s) scheme too,
// in case either is ever called directly. Without this, PHP's lenient
// parse_url() treats "javascript:alert(1)//a.mp4" as scheme "javascript" with
// path "alert(1)//a.mp4" — an extension match DirectFileProvider would
// otherwise happily claim.
test('DirectFileProvider refuses a non-http(s) URL even with a matching extension', function (string $url): void {
	expect((new DirectFileProvider())->matches($url))->toBeFalse();
})->with([
	'javascript:alert(1)//a.mp4',
	'data:video/mp4;base64,AAAA.mp4',
	'ftp://example.com/clip.mp4',
]);

test('UnknownProvider refuses a non-http(s) URL', function (string $url): void {
	expect((new UnknownProvider())->matches($url))->toBeFalse();
})->with([
	'javascript:alert(1)',
	'data:text/html,x',
	'ftp://example.com/whatever',
]);
