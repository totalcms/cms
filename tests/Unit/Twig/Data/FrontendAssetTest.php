<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Data\FrontendAsset;

test('constructs with all fields via named args', function (): void {
	$asset = new FrontendAsset(
		type: 'css',
		url: '/assets/foo.css',
		position: 'head',
		module: false,
		preload: true,
	);

	expect($asset->type)->toBe('css');
	expect($asset->url)->toBe('/assets/foo.css');
	expect($asset->position)->toBe('head');
	expect($asset->module)->toBeFalse();
	expect($asset->preload)->toBeTrue();
});

test('module and preload default to false', function (): void {
	$asset = new FrontendAsset(type: 'js', url: '/assets/x.js', position: 'body');

	expect($asset->module)->toBeFalse();
	expect($asset->preload)->toBeFalse();
});

test('properties are readonly', function (): void {
	$asset = new FrontendAsset(type: 'css', url: '/x', position: 'head');

	expect(fn (): string => $asset->url = '/y')->toThrow(Error::class);
});
