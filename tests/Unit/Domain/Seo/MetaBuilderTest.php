<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\MetaBuilder;

describe('MetaBuilder', function (): void {
	$b = new MetaBuilder();

	test('title: card → object title → site; through the template', function () use ($b): void {
		expect($b->build(seoCtx())->title)->toBe('Hello <World> | Bistro');
		expect($b->build(seoCtx(['fields' => SeoFields::fromArray(['title' => 'Custom'])]))->title)->toBe('Custom | Bistro');
		expect($b->build(seoCtx(['kind' => 'none', 'object' => [], 'url' => '']))->title)->toBe('Bistro');
		expect($b->build(seoCtx(['settings' => SeoSettings::fromArray(['titleTemplate' => '{site} – {title}'], 'x'), 'siteName' => 'S']))->title)->toBe('S – Hello <World>');
		expect($b->build(seoCtx(['settings' => SeoSettings::fromArray(['titleSeparator' => '·'], 'x')]))->title)->toBe('Hello <World> · Bistro');
	});

	test('title: mapped property, then placeholders on the card', function () use ($b): void {
		$obj = ['id' => 'x', 'title' => 'Fallback', 'name' => 'Mapped Name', 'author' => 'Joe'];
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => ['type' => '', 'title' => 'name', 'description' => '', 'image' => '']]))->rawTitle)->toBe('Mapped Name');
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => ['type' => '', 'title' => 'missing', 'description' => '', 'image' => '']]))->rawTitle)->toBe('Fallback');
		expect($b->build(seoCtx(['object' => $obj, 'fields' => SeoFields::fromArray(['title' => '${name} by ${author} – ${site}'])]))->rawTitle)->toBe('Mapped Name by Joe – Bistro');
		expect($b->build(seoCtx(['object' => $obj, 'fields' => SeoFields::fromArray(['title' => '${nope}'])]))->rawTitle)->toBe('Fallback');
		expect($b->build(seoCtx(['object' => ['id' => 'x', 'card' => ['headline' => 'Deep']], 'fields' => SeoFields::fromArray(['title' => '${card.headline}'])]))->rawTitle)->toBe('Deep');
	});

	test('title: a template whose placeholders are all empty falls through, literals and all', function () use ($b): void {
		// `${name} — ${city}` on a record with neither property must not emit a
		// bare `—` as the page title; the separator is not a title.
		$obj = ['id' => 'x', 'title' => 'Fallback'];
		expect($b->build(seoCtx(['object' => $obj, 'fields' => SeoFields::fromArray(['title' => '${name} — ${city}'])]))->rawTitle)->toBe('Fallback');
		// One placeholder resolving is a real, if partial, title.
		expect($b->build(seoCtx(['object' => $obj + ['name' => 'Bistro'], 'fields' => SeoFields::fromArray(['title' => '${name} — ${city}'])]))->rawTitle)->toBe('Bistro —');
		// A literal `${` with no closing brace is not a placeholder — the title
		// is the text the operator typed, not an empty fall-through.
		expect($b->build(seoCtx(['object' => $obj, 'fields' => SeoFields::fromArray(['title' => 'Cost ${100'])]))->rawTitle)->toBe('Cost ${100');
	});

	test('description: card → mapped field stripped of tags → site default', function () use ($b): void {
		expect($b->build(seoCtx())->description)->toBe('A summary & more');
		expect($b->build(seoCtx(['fields' => SeoFields::fromArray(['description' => 'Card'])]))->description)->toBe('Card');
		expect($b->build(seoCtx(['seoBlock' => ['type' => '', 'title' => '', 'description' => '', 'image' => '']]))->description)->toBe('Site default');
		$long = str_repeat('word ', 80);
		expect(mb_strlen($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => $long]]))->description))->toBeLessThanOrEqual(160);
	});

	test('an array-valued mapped property falls through instead of stringifying', function () use ($b): void {
		// The mapping select lists every schema property, image/card/deck
		// included — picking one must not emit `content="Array"`.
		$ctx = seoCtx(['object' => ['id' => 'x', 'title' => ['name' => 'hero.jpg'], 'summary' => ['a' => 1]]]);
		$p   = $b->build($ctx);
		expect($p->description)->toBe('Site default');
		expect($p->rawTitle)->toBe('');
		expect($p->title)->toBe('Bistro');
	});

	test('image: card → mapped field → site default, and the twitter card follows', function () use ($b): void {
		expect($b->build(seoCtx())->ogImage)->toBe('https://example.com/imageworks/blog/hello/image.jpg');
		$p = $b->build(seoCtx(['imageUrls' => ['image' => '', 'seo.image' => 'https://example.com/imageworks/blog/hello/seo.image.jpg'], 'fields' => SeoFields::fromArray(['image' => ['name' => 'c.jpg', 'size' => 5]])]));
		expect($p->ogImage)->toEndWith('seo.image.jpg')->and($p->twitterCard)->toBe('summary_large_image');
		$p = $b->build(seoCtx(['imageUrls' => ['image' => '', 'seo.image' => ''], 'settings' => SeoSettings::fromArray([], 'x')]));
		expect($p->ogImage)->toBe('')->and($p->twitterCard)->toBe('summary');
	});

	test('canonical, robots and og:type', function () use ($b): void {
		$p = $b->build(seoCtx());
		expect($p->canonical)->toBe('https://example.com/blog/hello')->and($p->robots)->toBe('')->and($p->ogType)->toBe('article')->and($p->noindex)->toBeFalse();
		expect($b->build(seoCtx(['fields' => SeoFields::fromArray(['canonical' => 'https://other.test/x'])]))->canonical)->toBe('https://other.test/x');
		expect($b->build(seoCtx(['fields' => SeoFields::fromArray(['noindex' => true, 'nofollow' => true])]))->robots)->toBe('noindex, nofollow');
		expect($b->build(seoCtx(['seoBlock' => ['type' => '', 'title' => '', 'description' => '', 'image' => '']]))->ogType)->toBe('website');
		// An explicit `website` on an otherwise-article context opts out.
		expect($b->build(seoCtx(['seoBlock' => ['type' => 'website', 'title' => 'title', 'description' => 'summary', 'image' => 'image']]))->ogType)->toBe('website');
		expect($b->build(seoCtx(['kind' => 'none', 'object' => [], 'url' => '']))->canonical)->toBe('');
	});
});
