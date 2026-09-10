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

	test('description: markdown markers are stripped from a mapped property', function () use ($b): void {
		$md = "**Bold** and [a link](https://x) plus `code`\n- item";
		expect($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => $md]]))->description)->toBe('Bold and a link plus code item');
		// HTML keeps flattening exactly as it did before the markdown pass.
		expect($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => '<p>Hi <b>there</b></p>']]))->description)->toBe('Hi there');
		// Prose with no markers comes through byte-identical — the strip only
		// runs when something looks like markdown.
		expect($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => 'Plain words, nothing to strip.']]))->description)->toBe('Plain words, nothing to strip.');
		// Headings, ordered lists and blockquotes lose their markers too.
		$doc = "# Heading\n> quoted\n1. first\n![alt text](a.jpg)";
		expect($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => $doc]]))->description)->toBe('Heading quoted first alt text');
	});

	test('description: prose that merely contains punctuation is never touched', function () use ($b): void {
		// An exclamation mark used to arm the whole strip pass, so ordinary
		// sentences lost asterisks and underscores. Neither line below is
		// markdown: `*` sits between spaces (multiplication, not emphasis) and
		// `_` sits inside a word.
		foreach (['Price: $5 * 3 items for the 2 * 2 deal!', 'Use my_var and your_var today!', 'Wow! 100% real (no markdown here).'] as $prose) {
			expect($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => $prose]]))->description)->toBe($prose);
		}
	});

	test('description: emphasis and strong wrappers lose their markers', function () use ($b): void {
		$cases = [
			'*emphasis* here'     => 'emphasis here',
			'_emphasis_ here'     => 'emphasis here',
			'**strong** here'     => 'strong here',
			'__strong__ here'     => 'strong here',
			// `__init__` IS a real `__x__` wrapper — nothing in a 160-character
			// description distinguishes the dunder from strong markdown, so it
			// strips. Accepted deliberately.
			'The __init__ method' => 'The init method',
		];
		foreach ($cases as $in => $out) {
			expect($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => $in]]))->description)->toBe($out);
		}
	});

	test('socialTitle rides the card through to the payload without touching the title', function () use ($b): void {
		$p = $b->build(seoCtx(['fields' => SeoFields::fromArray(['socialTitle' => ' Short '])]));
		expect($p->socialTitle)->toBe('Short')->and($p->title)->toBe('Hello <World> | Bistro')->and($p->rawTitle)->toBe('Hello <World>');
		expect($b->build(seoCtx())->socialTitle)->toBe('');
	});

	test('noindex leaves the canonical on the payload — the template decides whether to print it', function () use ($b): void {
		// og:url is still built from `canonical`, so dropping it here would take
		// the Open Graph URL with it. The `<link>` is skipped in head.twig.
		$p = $b->build(seoCtx(['fields' => SeoFields::fromArray(['noindex' => true])]));
		expect($p->noindex)->toBeTrue()->and($p->canonical)->toBe('https://example.com/blog/hello');
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

	test('image alt: follows whichever image won', function () use ($b): void {
		// The mapped image wins by default, so its alt is the one described.
		expect($b->build(seoCtx())->ogImageAlt)->toBe('Hero alt');

		// The card image wins, and takes its own alt with it.
		$p = $b->build(seoCtx([
			'imageUrls' => ['image' => 'https://example.com/imageworks/blog/hello/image.jpg', 'seo.image' => 'https://example.com/imageworks/blog/hello/seo.image.jpg'],
			'imageAlts' => ['image' => 'Hero alt', 'seo.image' => 'Card alt'],
			'fields'    => SeoFields::fromArray(['image' => ['name' => 'c.jpg', 'size' => 5]]),
		]));
		expect($p->ogImage)->toEndWith('seo.image.jpg')->and($p->ogImageAlt)->toBe('Card alt');

		// No image anywhere: an alt with nothing to describe is never emitted.
		$p = $b->build(seoCtx([
			'imageUrls' => ['image' => '', 'seo.image' => ''],
			'imageAlts' => ['image' => 'Hero alt', 'seo.image' => 'Card alt'],
			'settings'  => SeoSettings::fromArray([], 'x'),
		]));
		expect($p->ogImage)->toBe('')->and($p->ogImageAlt)->toBe('');

		// The site default image brings the site's default alt.
		$p = $b->build(seoCtx([
			'imageUrls' => ['image' => '', 'seo.image' => ''],
			'imageAlts' => ['image' => 'Hero alt', 'seo.image' => 'Card alt'],
			'settings'  => SeoSettings::fromArray(['defaultImage' => 'https://cdn/x.jpg', 'defaultImageAlt' => 'Share alt'], 'x'),
		]));
		expect($p->ogImage)->toBe('https://cdn/x.jpg')->and($p->ogImageAlt)->toBe('Share alt');
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
