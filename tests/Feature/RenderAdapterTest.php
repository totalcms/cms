<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\TwigEngine;

use function TotalCMS\Slim\Pest\postUpload;

/**
 * Behaviour of every `cms.render.*` helper that is not already pinned by a
 * unit test, exercised through the adapter's PUBLIC API against the real
 * container so the assertions survive the adapter being split into
 * per-concern renderers (image, gallery, load-more, clone dialog). Anything
 * here that reaches for a private method is a bug in the test.
 *
 * Load-more, the fragment URL helpers, alt text and the pagination delegates
 * are covered in tests/Unit/Domain/Twig/Adapter/; video in VideoRenderTest.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->render = $this->app->getContainer()->get(RenderTwigAdapter::class);
});

/** A gallery object with three images, one featured, each with alt/exif for captions. */
function galleryObject(): array
{
	return ['id' => 'trip', 'gallery' => [
		['name' => 'beach.jpg',  'alt' => 'Beach at dawn', 'width' => 1600, 'height' => 1200, 'featured' => false, 'hash' => 'aaaa', 'exif' => ['camera' => 'Canon EOS R5'], 'tags' => ['sea'],  'link' => ''],
		['name' => 'cliff.jpg',  'alt' => 'Cliff path',    'width' => 1200, 'height' => 1600, 'featured' => true,  'hash' => 'bbbb', 'exif' => ['camera' => 'Fuji X100'],    'tags' => ['hike'], 'link' => 'https://example.com/cliff'],
		['name' => 'dunes.jpg',  'alt' => '',              'width' => 800,  'height' => 600,  'featured' => false, 'hash' => 'cccc', 'exif' => [],                          'tags' => ['sea'],  'link' => ''],
	]];
}

/** An image object with a link and alt text. */
function imageObject(array $overrides = []): array
{
	return ['id' => 'hero', 'image' => array_merge([
		'name' => 'hero.jpg', 'alt' => 'Hero shot', 'width' => 1920, 'height' => 1080, 'hash' => 'h1h1', 'link' => '', 'mime' => 'image/jpeg', 'size' => 100,
	], $overrides)];
}

// ─── image() ─────────────────────────────────────────────────────────────────

test('image() renders an ImageWorks <img> with alt, dimensions, lazy loading and the class option', function (): void {
	$html = $this->render->image(imageObject(), ['w' => 600], ['class' => 'hero']);

	expect($html)->toStartWith('<img ')
		->and($html)->toContain('src="/imageworks/image/hero/image.jpg?w=600')
		->and($html)->toContain('alt="Hero shot"')
		->and($html)->toContain('width="600"')
		->and($html)->toContain('height="338"')
		->and($html)->toContain('loading="lazy"')
		->and($html)->toContain('class="hero"')
		->and($html)->not->toContain('<a ');
});

test('image() wraps the <img> in a link when the image carries one, and honours loading and a custom collection/property', function (): void {
	$object = ['id' => 'p1', 'photo' => imageObject(['link' => 'https://example.com/more'])['image']];

	$html = $this->render->image($object, [], ['collection' => 'products', 'property' => 'photo', 'loading' => 'eager']);

	expect($html)->toStartWith('<a href="https://example.com/more">')
		->and($html)->toContain('/imageworks/products/p1/photo.jpg')
		->and($html)->toContain('loading="eager"');
});

test('image() returns an empty string for an empty id or a missing object', function (): void {
	expect($this->render->image(null))->toBe('')
		->and($this->render->image(''))->toBe('')
		->and($this->render->image('does-not-exist'))->toBe('');
});

// ─── picture() ───────────────────────────────────────────────────────────────

test('picture() renders avif and webp sources plus a jpg fallback, each with a srcset of every default width up to the 1920 source', function (): void {
	$html = $this->render->picture(imageObject(), [], ['class' => 'hero']);

	expect($html)->toStartWith('<picture>')
		->and($html)->toEndWith('</picture>')
		->and($html)->toContain('<source type="image/avif" srcset="/imageworks/image/hero/image.avif?w=480')
		->and($html)->toContain('<source type="image/webp" srcset="/imageworks/image/hero/image.webp?w=480')
		->and($html)->toContain('<img src="/imageworks/image/hero/image.jpg?w=1920')
		// Every default width is present, each described by its delivered width.
		->and($html)->toContain('480w')->and($html)->toContain('768w')->and($html)->toContain('1024w')
		->and($html)->toContain('1440w')->and($html)->toContain('1920w')
		->and($html)->toContain('sizes="100vw"')
		->and($html)->toContain('alt="Hero shot"')
		->and($html)->toContain('width="1920"')
		->and($html)->toContain('height="1080"')
		->and($html)->toContain('loading="lazy"')
		->and($html)->toContain('class="hero"')
		// The fallback <img> carries its own srcset so no-<picture> browsers still get sizes.
		->and(substr_count($html, 'srcset="'))->toBe(3)
		->and(substr_count($html, '<source '))->toBe(2);
});

test('picture() never emits a candidate wider than the source, and adds the source width as the top candidate', function (): void {
	// An 800px-wide image against the default width list: 1024/1440/1920 must
	// go — Glide never upscales, so they would all deliver 800px and lie to
	// the browser — and 800 itself joins as the largest candidate.
	$html = $this->render->picture(imageObject(['width' => 800, 'height' => 600]));

	expect($html)->toContain('?w=480')
		->and($html)->toContain('?w=768')
		->and($html)->toContain('?w=800')
		->and($html)->toContain('800w')
		->and($html)->not->toContain('?w=1024')
		->and($html)->not->toContain('1024w')
		->and($html)->not->toContain('?w=1920')
		->and($html)->toContain('<img src="/imageworks/image/hero/image.jpg?w=800')
		->and($html)->toContain('width="800"')
		->and($html)->toContain('height="600"');
});

test('picture() honours explicit widths, formats and sizes, and a w in the transforms caps the largest candidate', function (): void {
	$html = $this->render->picture(
		imageObject(),
		['w' => 1200, 'q' => 70],
		['widths' => [400, 900, 1600], 'formats' => ['webp'], 'sizes' => '(min-width: 60em) 50vw, 100vw'],
	);

	expect(substr_count($html, '<source '))->toBe(1)
		->and($html)->toContain('type="image/webp"')
		->and($html)->not->toContain('image/avif')
		->and($html)->toContain('sizes="(min-width: 60em) 50vw, 100vw"')
		// With a base transform present `q` precedes `w` in the query string,
		// so match the width parameter itself rather than a leading `?`.
		->and($html)->toContain('w=400&amp;')
		->and($html)->toContain('w=900&amp;')
		// 1600 exceeds the caller's 1200 ceiling and is dropped; the ceiling is
		// not itself added (it is a cap, not a candidate).
		->and($html)->not->toContain('w=1600')
		->and($html)->not->toContain('w=1200')
		->and($html)->toContain('<img src="/imageworks/image/hero/image.jpg?q=70&amp;w=900')
		// The base transform reaches every candidate.
		->and(substr_count($html, 'q=70'))->toBeGreaterThanOrEqual(4);
});

test('picture() skips a <source> in the image\'s own format, wraps in the image\'s link, and returns nothing for an empty or missing image', function (): void {
	$webp = $this->render->picture(imageObject(['name' => 'hero.webp', 'mime' => 'image/webp', 'link' => 'https://example.com/more']));

	expect($webp)->toStartWith('<a href="https://example.com/more"><picture>')
		->and(substr_count($webp, '<source '))->toBe(1)
		->and($webp)->toContain('type="image/avif"')
		->and($webp)->not->toContain('type="image/webp"')
		->and($webp)->toContain('<img src="/imageworks/image/hero/image.webp?w=1920');

	// A GIF gets no <source> at all — re-encoding would drop animation.
	$gif = $this->render->picture(imageObject(['name' => 'loop.gif', 'mime' => 'image/gif']));
	expect($gif)->not->toContain('<source ')
		->and($gif)->toContain('<img src="/imageworks/image/hero/image.gif?w=1920');

	expect($this->render->picture(null))->toBe('')
		->and($this->render->picture(''))->toBe('')
		->and($this->render->picture('does-not-exist'))->toBe('')
		->and($this->render->picture(imageObject(['size' => 0])))->toBe('');
});

test('picture() is reachable from a template as cms.render.picture() with the same three-argument shape', function (): void {
	// The cases above call the adapter directly. This one goes through the
	// real engine so a rename, a __call collision or a missing sub-adapter
	// registration would surface here rather than on a live site.
	$html = $this->app->getContainer()->get(TwigEngine::class)->renderString(
		"{{ cms.render.picture(hero, {q: 80}, {sizes: '50vw', class: 'from-twig'}) }}",
		['hero' => imageObject()],
	);

	expect($html)->toStartWith('<picture>')
		->and($html)->toContain('<source type="image/avif"')
		->and($html)->toContain('sizes="50vw"')
		->and($html)->toContain('q=80')
		->and($html)->toContain('class="from-twig"')
		->and($html)->toContain('1920w');
});

// ─── gallery() ───────────────────────────────────────────────────────────────

test('gallery() renders one figure per image with thumb and full ImageWorks URLs, sizes, and the container settings', function (): void {
	$html = $this->render->gallery(galleryObject(), ['w' => 150, 'h' => 100], ['w' => 1200], ['class' => 'photos', 'loop' => false]);

	expect(substr_count($html, '<figure class="cms-gallery-item"'))->toBe(3)
		->and($html)->toContain('class="cms-gallery photos"')
		->and($html)->toContain('href="/imageworks/gallery/trip/gallery/beach.jpg?w=1200')
		->and($html)->toContain('src="/imageworks/gallery/trip/gallery/beach.jpg?w=150&amp;h=100')
		->and($html)->toContain('data-lg-size="1200-900"')
		->and($html)->toContain('alt="Beach at dawn"')
		->and($html)->toContain('loading="lazy"');

	// Settings handed to lightGallery: the caller's options minus the ones
	// the renderer consumes, plus the fixed caption guard.
	preg_match('/data-settings="([^"]+)"/', $html, $m);
	$settings = json_decode(html_entity_decode($m[1]), true);
	expect($settings)->toMatchArray(['loop' => false, 'getCaptionFromTitleOrAlt' => false])
		->and($settings)->not->toHaveKeys(['collection', 'property', 'class', 'captions', 'gridCaptions', 'featuredOnly', 'sort']);
});

test('gallery() default thumbnail size is 300x200 when no thumb settings are given', function (): void {
	$html = $this->render->gallery(galleryObject());

	expect($html)->toContain('gallery/beach.jpg?w=300&amp;h=200');
});

test('gallery() grid captions and lightbox captions: default alt, a template, and nothing for an image with no caption', function (): void {
	$html = $this->render->gallery(galleryObject(), [], [], ['gridCaptions' => true, 'captions' => '<b>{alt}</b> — {exif.camera}']);

	expect($html)->toContain('<figcaption class="cms-gallery-caption">Beach at dawn</figcaption>')
		// The template's HTML lives inside an attribute, so it is entity-encoded
		// there (the browser decodes it before lightGallery reads it).
		->and($html)->toContain('data-sub-html="&lt;b&gt;Beach at dawn&lt;/b&gt; — Canon EOS R5"')
		// dunes.jpg has no alt: the default grid caption (plain alt) is skipped
		// for it, but a template keeps its separators (" — ") and so still
		// yields a lightbox caption — the same rule the caption cases below pin.
		->and(substr_count($html, '<figcaption'))->toBe(2)
		->and(substr_count($html, 'data-sub-html='))->toBe(3)
		->and($html)->toContain('data-sub-html="&lt;b&gt;&lt;/b&gt; —"');
});

test('gallery() featuredOnly shows only featured thumbnails but ships every image to the lightbox', function (): void {
	$html = $this->render->gallery(galleryObject(), [], [], ['featuredOnly' => true, 'captions' => true]);

	expect(substr_count($html, '<figure class="cms-gallery-item"'))->toBe(1)
		->and($html)->toContain('data-gallery-image="cliff.jpg"')
		->and($html)->toContain('<template class="cms-gallery-dynamic">');

	preg_match('#<template class="cms-gallery-dynamic">(.*)</template>#', $html, $m);
	$dynamic = json_decode($m[1], true);
	expect(array_column($dynamic, 'name'))->toBe(['beach.jpg', 'cliff.jpg', 'dunes.jpg'])
		->and($dynamic[0])->toMatchArray(['lgSize' => '1600-1200', 'subHtml' => 'Beach at dawn'])
		->and($dynamic[2])->not->toHaveKey('subHtml');
});

test('gallery() sorts by a property, descending with a leading dash', function (): void {
	$html = $this->render->gallery(galleryObject(), [], [], ['sort' => '-name']);

	expect(strpos($html, 'dunes.jpg'))->toBeLessThan(strpos($html, 'cliff.jpg'))
		->and(strpos($html, 'cliff.jpg'))->toBeLessThan(strpos($html, 'beach.jpg'));
});

test('gallery() maxVisible and viewAllText become data attributes for the JS', function (): void {
	$html = $this->render->gallery(galleryObject(), [], [], ['maxVisible' => 2, 'viewAllText' => 'See <all>']);

	// Encoded exactly once: HTMLUtils escapes attribute values itself, so the
	// renderer must not pre-escape (that used to double-encode to &amp;lt;).
	expect($html)->toContain('data-max-visible="2"')
		->and($html)->toContain('data-view-all-text="See &lt;all&gt;"');
});

test('gallery() and galleryLauncher() hand zoomFromOrigin to lightGallery untouched', function (): void {
	// The thumbnail-zoom animation stretches thumbnails cropped to another
	// shape (lightGallery #1698); the documented way out is this setting, so
	// it must survive the option scrubbing on both renderers.
	foreach (['gallery', 'galleryLauncher'] as $method) {
		$html = $this->render->{$method}(galleryObject(), ['w' => 300, 'h' => 300, 'fit' => 'crop'], [], ['zoomFromOrigin' => false]);

		preg_match('/data-settings="([^"]+)"/', $html, $m);
		$settings = json_decode(html_entity_decode($m[1]), true);
		expect($settings)->toMatchArray(['zoomFromOrigin' => false], $method);
	}
});

test('gallery() returns an empty string for nothing, an unknown id, and an object without images', function (): void {
	expect($this->render->gallery(null))->toBe('')
		->and($this->render->gallery('nope'))->toBe('')
		->and($this->render->gallery(['id' => 'x', 'gallery' => []]))->toBe('');
});

// ─── galleryLauncher() ───────────────────────────────────────────────────────

test('galleryLauncher() renders a <template> with the gallery id and the lightGallery items as JSON', function (): void {
	$html = $this->render->galleryLauncher(galleryObject(), ['w' => 300], ['w' => 1920], ['captions' => true, 'trigger' => '.open']);

	expect($html)->toStartWith('<template data-gallery-id="gallery-trip"')
		->and($html)->toContain('data-settings="');

	preg_match('#<template[^>]*>(.*)</template>#', $html, $m);
	$items = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
	expect($items)->toHaveCount(3)
		->and($items[0])->toMatchArray(['name' => 'beach.jpg', 'lgSize' => '1600-1200', 'subHtml' => 'Beach at dawn'])
		->and($items[0]['src'])->toContain('gallery/beach.jpg?w=1920')
		->and($items[0]['thumb'])->toContain('gallery/beach.jpg?w=300')
		->and($items[2])->not->toHaveKey('subHtml');

	preg_match('/data-settings="([^"]+)"/', $html, $s);
	$settings = json_decode(html_entity_decode($s[1], ENT_QUOTES), true);
	expect($settings)->toMatchArray(['trigger' => '.open', 'getCaptionFromTitleOrAlt' => false])
		->and($settings)->not->toHaveKeys(['captions', 'galleryId', 'collection', 'property']);
});

test('galleryLauncher() honours a custom galleryId, include/exclude filters, search and sort', function (): void {
	$names = function (string $html): array {
		preg_match('#<template[^>]*>(.*)</template>#', $html, $m);

		return array_column(json_decode(html_entity_decode($m[1], ENT_QUOTES), true), 'name');
	};

	expect($this->render->galleryLauncher(galleryObject(), [], [], ['galleryId' => 'custom']))->toContain('data-gallery-id="custom"');
	expect($names($this->render->galleryLauncher(galleryObject(), [], [], ['include' => 'tags:sea'])))->toBe(['beach.jpg', 'dunes.jpg']);
	expect($names($this->render->galleryLauncher(galleryObject(), [], [], ['exclude' => 'featured:true'])))->toBe(['beach.jpg', 'dunes.jpg']);
	expect($names($this->render->galleryLauncher(galleryObject(), [], [], ['search' => 'cliff'])))->toBe(['cliff.jpg']);
	expect($names($this->render->galleryLauncher(galleryObject(), [], [], ['sort' => '-name'])))->toBe(['dunes.jpg', 'cliff.jpg', 'beach.jpg']);
});

// ─── galleryImage() ──────────────────────────────────────────────────────────

test('galleryImage() renders one gallery image with launcher hooks, alt, class, and a link wrap when the image has one', function (): void {
	$html = $this->render->galleryImage(galleryObject(), 'cliff.jpg', ['w' => 400], ['class' => 'thumb']);

	expect($html)->toStartWith('<a href="https://example.com/cliff">')
		->and($html)->toContain('src="/imageworks/gallery/trip/gallery/cliff.jpg?w=400')
		->and($html)->toContain('alt="Cliff path"')
		->and($html)->toContain('data-gallery="gallery-trip"')
		->and($html)->toContain('data-gallery-image="cliff.jpg"')
		->and($html)->toContain('class="thumb"');

	$plain = $this->render->galleryImage(galleryObject(), 'beach.jpg');
	expect($plain)->toStartWith('<img ')->and($plain)->not->toContain('<a ');
});

test('galleryImage() resolves the first/last/featured tokens and returns nothing for a missing name', function (): void {
	expect($this->render->galleryImage(galleryObject(), 'first'))->toContain('data-gallery-image="beach.jpg"')
		->and($this->render->galleryImage(galleryObject(), 'last'))->toContain('data-gallery-image="dunes.jpg"')
		->and($this->render->galleryImage(galleryObject(), 'featured'))->toContain('data-gallery-image="cliff.jpg"')
		->and($this->render->galleryImage(galleryObject(), null))->toBe('')
		->and($this->render->galleryImage(galleryObject(), 'missing.jpg'))->toBe('');
});

// ─── galleryCaption() templates (was a reflection test on a private helper) ──

$captionCases = [
	'single brace'            => ['{alt}', ['alt' => 'Sunset photo'], 'Sunset photo'],
	'spaced braces'           => ['{ alt }', ['alt' => 'Sunset photo'], 'Sunset photo'],
	'html preserved'          => ['<h4>{alt}</h4>', ['alt' => 'Sunset photo'], '<h4>Sunset photo</h4>'],
	'nested dot notation'     => ['{exif.camera}', ['exif' => ['camera' => 'Canon EOS R5']], 'Canon EOS R5'],
	'missing variable'        => ['{alt}', [], ''],
	'all-empty is empty'      => ['{alt}', ['alt' => ''], ''],
	'separators survive'      => ['<span>{alt}</span> - <span>{title}</span>', ['alt' => '', 'title' => ''], '<span></span> - <span></span>'],
	'twig filters'            => ['{alt|upper}', ['alt' => 'hello'], 'HELLO'],
	'invalid template'        => ['{%invalid%}', [], ''],
];

test('galleryCaption() renders a caption template: {case}', function (string $template, array $image, string $expected): void {
	$object = ['id' => 'g', 'gallery' => [array_merge(['name' => 'a.jpg', 'width' => 10, 'height' => 10], $image)]];

	expect($this->render->galleryCaption($object, 'a.jpg', [], $template))->toBe($expected);
})->with($captionCases);

// ─── depotBrowser() ──────────────────────────────────────────────────────────

test('depotBrowser() renders the files of a depot object and nothing for an unknown object', function (): void {
	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('depot');
	$c->get(ObjectSaver::class)->saveObject('depot', ['id' => 'docs']);
	$file = sys_get_temp_dir() . '/depot-' . uniqid() . '.txt';
	file_put_contents($file, "depot file\n");
	expect(postUpload('/api/collections/depot/docs/depot', $file, 'text/plain', 'depot')->getStatusCode())->toBe(200);

	$html = $this->render->depotBrowser('docs', ['class' => 'my-depot']);
	expect($html)->toContain('my-depot')
		->and($html)->toContain(basename($file, '.txt'))
		->and($html)->toContain('/download/');

	expect($this->render->depotBrowser('nope'))->toBe('');
});

// ─── cloneDialog() ───────────────────────────────────────────────────────────

test('cloneDialog() renders the clone form for a collection with itself preselected, and nothing for an unknown one', function (): void {
	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');

	$html = $this->render->cloneDialog('blog');
	expect($html)->toContain('dialog-clone-object')
		->and($html)->toContain('<h3>Clone ')
		->and($html)->toContain('<option value="blog" selected')
		->and($html)->toContain('name="id"')
		->and($html)->toContain('clone-object-form');

	expect($this->render->cloneDialog('nope'))->toBe('');
});

// ─── pagination delegates ────────────────────────────────────────────────────

test('paginationSimple() and paginationFull() delegate to the pagination generator', function (): void {
	expect($this->render->paginationSimple(50, 2, 10))->toContain('Next')
		->and($this->render->paginationFull(50, 2, 10))->toContain('Previous');
});
