<?php

declare(strict_types=1);

use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Provider\YouTubeProvider;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

test('near misses are not claimed', function (): void {
	$r = new VideoUrlResolver(VideoUrlResolver::defaultProviders());
	expect($r->resolve('https://youtube.com/')->provider)->toBe('unknown');
	expect($r->resolve('https://vimeo.com/about')->provider)->toBe('unknown');
	expect($r->resolve('https://example.com/clip.mp4.html')->provider)->toBe('unknown');
	// A Publitio direct-file link is a plain file, not the player page — the
	// author picks native <video> vs the Publitio player by which link they paste.
	expect($r->resolve('https://media.weaversspace.com/file/weaversspace/play/weaversspace-intro-z.mp4')->provider)->toBe('file');
});

test('defaultProviders() orders YouTube first and Unknown last', function (): void {
	$ids = array_map(static fn ($provider) => $provider->id(), VideoUrlResolver::defaultProviders());

	expect($ids)->toBe(['youtube', 'vimeo', 'livid', 'bunny', 'cloudflare', 'loom', 'wistia', 'publitio', 'file', 'unknown']);
});

test('a resolver constructed with no providers always falls back to the empty unknown VideoInfo', function (): void {
	$resolver = new VideoUrlResolver();

	expect($resolver->resolve('https://www.youtube.com/watch?v=abc123XYZ_-'))->toEqual(new VideoInfo('unknown', '', '', '', null));
});

test('resolve() trims whitespace and decodes a pre-escaped URL before matching', function (): void {
	$resolver = new VideoUrlResolver([new YouTubeProvider()]);
	$info     = $resolver->resolve('  https://www.youtube.com/watch?si=xyz&amp;v=abc123XYZ_-  ');

	expect($info->provider)->toBe('youtube');
	expect($info->videoId)->toBe('abc123XYZ_-');
});

// Final review fix (Critical #1): a non-http(s) URL must never reach the
// provider chain — it resolves to 'unknown' with an EMPTY embedUrl (not the
// raw value), which is the render layer's signal that nothing is safe to
// embed. Real providers are wired up here to prove they're never even asked.
test('a non-http(s) URL resolves to unknown with an empty embed, never reaching a provider', function (string $url): void {
	$resolver = new VideoUrlResolver(VideoUrlResolver::defaultProviders());

	expect($resolver->resolve($url))->toEqual(new VideoInfo('unknown', '', '', '', null));
})->with([
	'javascript:alert(1)',
	'data:text/html,x',
	'javascript:alert(1)//a.mp4',
	'  javascript:alert(1)  ',
	'JAVASCRIPT:alert(1)',
]);

// Final review follow-up (Important #1): the resolver rejects a URL only
// when it carries an EXPLICIT scheme other than http/https. A schemeless
// relative URL or a protocol-relative one has no scheme token at all, so it
// must reach the provider chain exactly as it did before the video field
// shipped — narrowing the earlier "no https?:// prefix" predicate, which
// wrongly rejected these too.
test('hasUnsafeScheme() rejects only a URL with an explicit non-http(s) scheme', function (string $url, bool $unsafe): void {
	expect(VideoUrlResolver::hasUnsafeScheme($url))->toBe($unsafe);
})->with([
	['javascript:alert(1)', true],
	['data:text/html,x', true],
	['ftp://example.com/clip.mp4', true],
	['mailto:x@example.com', true],
	['https://example.com/page', false],
	['http://example.com/page', false],
	['/embeds/thing.html', false],
	['//cdn.example.com/page', false],
	['/videos/clip.mp4', false],
	['not a url', false],
	['', false],
]);

test('a schemeless relative URL is not rejected and reaches the provider chain', function (): void {
	$resolver = new VideoUrlResolver(VideoUrlResolver::defaultProviders());

	$page = $resolver->resolve('/embeds/thing.html');
	expect($page->provider)->toBe('unknown');
	expect($page->embedUrl)->toBe('/embeds/thing.html');

	$file = $resolver->resolve('/videos/clip.mp4');
	expect($file->provider)->toBe('file');
	expect($file->embedUrl)->toBe('/videos/clip.mp4');
});

test('a protocol-relative URL is not rejected and reaches the provider chain', function (): void {
	$resolver = new VideoUrlResolver(VideoUrlResolver::defaultProviders());

	$info = $resolver->resolve('//cdn.example.com/page');
	expect($info->provider)->toBe('unknown');
	expect($info->embedUrl)->toBe('//cdn.example.com/page');
});
