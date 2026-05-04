<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Domain\Twig\Service\AssetRenderer;

// ===== head() =====

test('head returns empty string for no assets', function (): void {
	expect(AssetRenderer::head([]))->toBe('');
});

test('head emits stylesheet for head-positioned css', function (): void {
	$assets = [new FrontendAsset(type: 'css', url: '/api/assets/foo.css', position: 'head')];

	expect(AssetRenderer::head($assets))->toContain('<link rel="stylesheet" href="/api/assets/foo.css"');
});

test('head skips body-positioned css', function (): void {
	$assets = [new FrontendAsset(type: 'css', url: '/api/assets/x.css', position: 'body')];

	expect(AssetRenderer::head($assets))->toBe('');
});

test('head emits modulepreload for js+module+preload', function (): void {
	$assets = [new FrontendAsset(
		type: 'js',
		url: '/api/assets/x.js',
		position: 'body',
		module: true,
		preload: true,
	)];

	expect(AssetRenderer::head($assets))->toContain('<link rel="modulepreload" href="/api/assets/x.js"');
});

test('head emits preload as=style for css+preload', function (): void {
	$assets = [new FrontendAsset(
		type: 'css',
		url: '/api/assets/x.css',
		position: 'head',
		preload: true,
	)];

	$html = AssetRenderer::head($assets);

	expect($html)->toContain('<link rel="preload" as="style" href="/api/assets/x.css"');
});

test('head emits preload as=script for non-module js+preload', function (): void {
	$assets = [new FrontendAsset(
		type: 'js',
		url: '/api/assets/legacy.js',
		position: 'body',
		module: false,
		preload: true,
	)];

	expect(AssetRenderer::head($assets))->toContain('<link rel="preload" as="script" href="/api/assets/legacy.js"');
});

test('head skips assets without preload flag', function (): void {
	$assets = [new FrontendAsset(
		type: 'js',
		url: '/api/assets/x.js',
		position: 'body',
		module: true,
		preload: false,
	)];

	expect(AssetRenderer::head($assets))->not->toContain('preload');
});

test('head emits script tag for head-positioned js', function (): void {
	$assets = [new FrontendAsset(type: 'js', url: '/api/assets/inline.js', position: 'head')];

	expect(AssetRenderer::head($assets))->toContain('<script src="/api/assets/inline.js"');
});

test('head emits script type=module for module js in head', function (): void {
	$assets = [new FrontendAsset(
		type: 'js',
		url: '/api/assets/m.js',
		position: 'head',
		module: true,
	)];

	expect(AssetRenderer::head($assets))->toContain('<script type="module" src="/api/assets/m.js"');
});

test('head orders output: stylesheets, then preloads, then head scripts', function (): void {
	$assets = [
		new FrontendAsset(type: 'js',  url: '/api/m.js',  position: 'body', module: true,  preload: true),
		new FrontendAsset(type: 'css', url: '/api/a.css', position: 'head'),
		new FrontendAsset(type: 'js',  url: '/api/h.js',  position: 'head'),
	];

	$html = AssetRenderer::head($assets);

	$stylePos   = strpos($html, '/api/a.css');
	$preloadPos = strpos($html, 'modulepreload');
	$scriptPos  = strpos($html, '/api/h.js');

	expect($stylePos)->not->toBeFalse();
	expect($preloadPos)->not->toBeFalse();
	expect($scriptPos)->not->toBeFalse();
	expect($stylePos)->toBeLessThan($preloadPos);
	expect($preloadPos)->toBeLessThan($scriptPos);
});

// ===== body() =====

test('body returns empty string for no assets', function (): void {
	expect(AssetRenderer::body([]))->toBe('');
});

test('body emits script tag for body-positioned js', function (): void {
	$assets = [new FrontendAsset(type: 'js', url: '/api/assets/app.js', position: 'body')];

	expect(AssetRenderer::body($assets))->toContain('<script src="/api/assets/app.js"');
});

test('body emits script type=module for module js in body', function (): void {
	$assets = [new FrontendAsset(
		type: 'js',
		url: '/api/assets/m.js',
		position: 'body',
		module: true,
	)];

	expect(AssetRenderer::body($assets))->toContain('<script type="module" src="/api/assets/m.js"');
});

test('body emits stylesheet for body-positioned css', function (): void {
	$assets = [new FrontendAsset(type: 'css', url: '/api/assets/late.css', position: 'body')];

	expect(AssetRenderer::body($assets))->toContain('<link rel="stylesheet" href="/api/assets/late.css"');
});

test('body skips head-positioned assets', function (): void {
	$assets = [
		new FrontendAsset(type: 'css', url: '/api/h.css', position: 'head'),
		new FrontendAsset(type: 'js',  url: '/api/h.js',  position: 'head'),
	];

	expect(AssetRenderer::body($assets))->toBe('');
});

test('body orders css before js', function (): void {
	$assets = [
		new FrontendAsset(type: 'js',  url: '/api/app.js',  position: 'body'),
		new FrontendAsset(type: 'css', url: '/api/late.css', position: 'body'),
	];

	$html = AssetRenderer::body($assets);

	$cssPos = strpos($html, '/api/late.css');
	$jsPos  = strpos($html, '/api/app.js');

	expect($cssPos)->toBeLessThan($jsPos);
});

// ===== shared =====

test('URLs are emitted verbatim, not re-prefixed', function (): void {
	$assets = [new FrontendAsset(type: 'css', url: 'https://cdn.example.com/x.css', position: 'head')];

	expect(AssetRenderer::head($assets))->toContain('href="https://cdn.example.com/x.css"');
});

test('URL attribute values are HTML-escaped', function (): void {
	$assets = [new FrontendAsset(type: 'css', url: '/api/a.css?x=1&y=2', position: 'head')];

	$html = AssetRenderer::head($assets);

	expect($html)->toContain('href="/api/a.css?x=1&amp;y=2"');
	expect($html)->not->toContain('?x=1&y=2"');
});
