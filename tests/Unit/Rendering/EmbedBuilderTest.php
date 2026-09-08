<?php

use TotalCMS\Domain\Rendering\Utilities\EmbedBuilder;

beforeEach(function (): void {
	$_SERVER['HTTP_HOST'] = 'example.com';
});

test('youtube embed escapes query separators exactly once', function (): void {
	$html = EmbedBuilder::embed('https://www.youtube.com/watch?v=zzd4fqqPsCs', ['autoplay' => false]);

	expect($html)->toContain('www.youtube-nocookie.com/embed/zzd4fqqPsCs');
	expect($html)->toContain('autoplay=0&amp;loop=0');
	expect($html)->not->toContain('&amp;amp;');
});

test('vimeo embed escapes query separators exactly once', function (): void {
	$html = EmbedBuilder::embed('https://vimeo.com/123456789', []);

	expect($html)->toContain('player.vimeo.com/video/123456789');
	expect($html)->toContain('&amp;');
	expect($html)->not->toContain('&amp;amp;');
});

test('youtube embed accepts a pre-escaped source url', function (): void {
	$html = EmbedBuilder::embed('https://www.youtube.com/watch?si=xyz&amp;v=zzd4fqqPsCs', []);

	expect($html)->toContain('www.youtube-nocookie.com/embed/zzd4fqqPsCs');
	expect($html)->not->toContain('&amp;amp;');
});

test('generic iframe embed does not double escape a pre-escaped url', function (): void {
	$html = EmbedBuilder::embed('https://example.com/page?a=1&amp;b=2', []);

	expect($html)->toContain('a=1&amp;b=2');
	expect($html)->not->toContain('&amp;amp;');
});

test('embed supports youtu.be short links', function (): void {
	$html = EmbedBuilder::embed('https://youtu.be/zzd4fqqPsCs', []);

	expect($html)->toContain('www.youtube-nocookie.com/embed/zzd4fqqPsCs');
	expect($html)->not->toContain('&amp;amp;');
});

test('embed supports youtube shorts links', function (): void {
	$html = EmbedBuilder::embed('https://www.youtube.com/shorts/zzd4fqqPsCs', []);

	expect($html)->toContain('www.youtube-nocookie.com/embed/zzd4fqqPsCs');
});

test('embed renders a videoseries embed for a youtube playlist URL', function (): void {
	$html = EmbedBuilder::embed('https://www.youtube.com/playlist?list=PLxyz999', []);

	expect($html)->toContain('www.youtube-nocookie.com/embed/videoseries?list=PLxyz999');
});

test('youtube embed with a watch+list URL uses the real playlist id, not the video id', function (): void {
	$html = EmbedBuilder::embed('https://www.youtube.com/watch?v=abcdefghijk&list=PLxyz999', []);

	expect($html)->toContain('list=PLxyz999');
	expect($html)->not->toContain('list=abcdefghijk');
});

test('embed renders a generic iframe for a Livid video', function (): void {
	$html = EmbedBuilder::embed('https://livid.com/watch/xyz789', []);

	expect($html)->toContain('data-src="https://livid.com/embed/xyz789"');
	expect($html)->toContain('class="cms-iframe cms-video-embed"');
});

test('embed iframes carry an explicit referrer policy', function (): void {
	// YouTube refuses embeds that arrive with no Referer (error 153), and a
	// site-wide no-referrer policy silently produces exactly that. The
	// attribute restates the browser default so a strict page-level policy
	// can't break the player — same attribute YouTube's own snippet ships.
	$youtube = EmbedBuilder::embed('https://www.youtube.com/watch?v=zzd4fqqPsCs', []);
	$vimeo   = EmbedBuilder::embed('https://vimeo.com/123456789', []);
	$generic = EmbedBuilder::embed('https://example.com/page', []);

	expect($youtube)->toContain('referrerpolicy="strict-origin-when-cross-origin"');
	expect($vimeo)->toContain('referrerpolicy="strict-origin-when-cross-origin"');
	expect($generic)->toContain('referrerpolicy="strict-origin-when-cross-origin"');
});

test('embed() refuses a URL with an unsafe scheme instead of rendering an iframe/audio', function (): void {
	// Final review fix (Critical #1): a URL that carries an actual scheme
	// other than http/https is never safe to embed. Checked up front, before
	// the mp3 shortcut and the resolver.
	expect(EmbedBuilder::embed('javascript:alert(1)'))->toBe('');
	expect(EmbedBuilder::embed('data:text/html,<script>alert(1)</script>'))->toBe('');
});

test('embed() refuses an unsafe-scheme URL even when it ends in mp3 (follow-up #3)', function (): void {
	// The .mp3 shortcut used to run BEFORE the resolver/guard, so
	// embed('javascript:alert(1)//a.mp3') built an <audio src="javascript:…">
	// element. The unsafe-scheme check now runs first.
	$html = EmbedBuilder::embed('javascript:alert(1)//a.mp3');

	expect($html)->toBe('');
});

test('embed() renders an iframe for a schemeless relative URL (follow-up #1)', function (): void {
	// A relative URL has no scheme at all — it is NOT unsafe, and must keep
	// rendering an iframe exactly as it did before the video field shipped.
	$html = EmbedBuilder::embed('/embeds/thing.html');

	expect($html)->toContain('<iframe')
		->and($html)->toContain('data-src="/embeds/thing.html"');
});

test('embed() renders an iframe for a protocol-relative URL (follow-up #1)', function (): void {
	$html = EmbedBuilder::embed('//cdn.example.com/page');

	expect($html)->toContain('<iframe')
		->and($html)->toContain('data-src="//cdn.example.com/page"');
});

test('embed() renders a <video> for a schemeless relative file URL (follow-up #1)', function (): void {
	$html = EmbedBuilder::embed('/videos/clip.mp4');

	expect($html)->toContain('<video')
		->and($html)->toContain('src="/videos/clip.mp4"');
});
