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
