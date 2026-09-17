<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\MetaBuilder;

describe('MetaBuilder', function (): void {
	$b = new MetaBuilder();

	test('title: card → collection template → object title → site; only the object title goes through the site template', function () use ($b): void {
		// The object's own title is the derived case: the site template shapes it.
		expect($b->build(seoCtx())->title)->toBe('Hello <World> | Bistro');
		expect($b->build(seoCtx(['kind' => 'none', 'object' => [], 'url' => '']))->title)->toBe('Bistro');
		expect($b->build(seoCtx(['settings' => SeoSettings::fromArray(['titleTemplate' => '${site} – ${title}'], 'x'), 'siteName' => 'S']))->title)->toBe('S – Hello <World>');

		// A title someone authored — on the card — is the whole <title>. The
		// site template is the default shape, not a wrapper around every title.
		expect($b->build(seoCtx(['fields' => SeoFields::fromArray(['title' => 'Custom'])]))->title)->toBe('Custom');
	});

	test('title: the collection template composes from the object and is used verbatim, not merged with the site template', function () use ($b): void {
		$obj   = ['id' => 'x', 'title' => 'Post', 'name' => 'Tony', 'author' => 'Joe'];
		$block = ['type' => '', 'title' => 'YETI - ${title}', 'socialTitle' => '', 'description' => '', 'image' => ''];
		$p     = $b->build(seoCtx(['object' => $obj, 'seoBlock' => $block]));
		expect($p->rawTitle)->toBe('YETI - Post')->and($p->title)->toBe('YETI - Post');

		// The collection template can bring the site name in itself.
		$site = ['type' => '', 'title' => '${title} | ${site} Blog', 'socialTitle' => '', 'description' => '', 'image' => ''];
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => $site]))->title)->toBe('Post | Bistro Blog');

		// The card's own title still wins over the collection template.
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => $block, 'fields' => SeoFields::fromArray(['title' => 'Custom'])]))->title)->toBe('Custom');

		// A collection template whose placeholders all come back empty falls
		// through to the object's title, which then takes the site template.
		$empty = ['type' => '', 'title' => 'YETI - ${nothing}', 'socialTitle' => '', 'description' => '', 'image' => ''];
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => $empty]))->title)->toBe('Post | Bistro');
	});

	test('socialTitle: card, then the collection social template, then the raw title', function () use ($b): void {
		$obj   = ['id' => 'x', 'title' => 'Post', 'name' => 'Tony'];
		$block = ['type' => '', 'title' => '', 'socialTitle' => 'Read: ${title}', 'description' => '', 'image' => ''];
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => $block]))->socialTitle)->toBe('Read: Post');
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => $block, 'fields' => SeoFields::fromArray(['socialTitle' => 'Short'])]))->socialTitle)->toBe('Short');
		// A collection template whose placeholders all come back empty falls through.
		$empty = ['type' => '', 'title' => '', 'socialTitle' => 'Read: ${nothing}', 'description' => '', 'image' => ''];
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => $empty]))->socialTitle)->toBe('Post');
	});

	test('content type: card, then collection, then Web page; drives og:type and the article dates', function () use ($b): void {
		$obj   = ['id' => 'x', 'title' => 'Post', 'date' => '2026-01-02T00:00:00+00:00', 'created' => '2025-12-31T00:00:00+00:00', 'updated' => '2026-02-01T00:00:00+00:00'];
		$block = fn (string $type): array => ['type' => $type, 'title' => '', 'socialTitle' => '', 'description' => '', 'image' => ''];

		$p = $b->build(seoCtx(['object' => $obj, 'seoBlock' => $block('')]));
		expect($p->contentType)->toBe('website')->and($p->ogType)->toBe('website')->and($p->publishedTime)->toBe('')->and($p->modifiedTime)->toBe('');

		$p = $b->build(seoCtx(['object' => $obj, 'seoBlock' => $block('blogposting')]));
		expect($p->contentType)->toBe('blogposting')->and($p->ogType)->toBe('article')
			->and($p->publishedTime)->toBe('2026-01-02T00:00:00+00:00')->and($p->modifiedTime)->toBe('2026-02-01T00:00:00+00:00');

		// The card overrides the collection in both directions.
		$p = $b->build(seoCtx(['object' => $obj, 'seoBlock' => $block('blogposting'), 'fields' => SeoFields::fromArray(['jsonldType' => 'website'])]));
		expect($p->contentType)->toBe('website')->and($p->ogType)->toBe('website');
		$p = $b->build(seoCtx(['object' => $obj, 'seoBlock' => $block(''), 'fields' => SeoFields::fromArray(['jsonldType' => 'article'])]));
		expect($p->contentType)->toBe('article')->and($p->ogType)->toBe('article');

		// Published falls back to created; a value outside the list is ignored.
		$p = $b->build(seoCtx(['object' => ['id' => 'x', 'title' => 'P', 'created' => '2025-12-31T00:00:00+00:00'], 'seoBlock' => $block('article')]));
		expect($p->publishedTime)->toBe('2025-12-31T00:00:00+00:00');
		expect($b->build(seoCtx(['seoBlock' => $block('none')]))->contentType)->toBe('website');
	});

	test('title: mapped property, then placeholders on the card', function () use ($b): void {
		$obj = ['id' => 'x', 'title' => 'Fallback', 'name' => 'Mapped Name', 'author' => 'Joe'];
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => ['type' => '', 'title' => '${name}', 'socialTitle' => '', 'description' => '', 'image' => '']]))->rawTitle)->toBe('Mapped Name');
		expect($b->build(seoCtx(['object' => $obj, 'seoBlock' => ['type' => '', 'title' => '${missing}', 'socialTitle' => '', 'description' => '', 'image' => '']]))->rawTitle)->toBe('Fallback');
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
		expect($b->build(seoCtx(['seoBlock' => ['type' => '', 'title' => '', 'socialTitle' => '', 'description' => '', 'image' => '']]))->description)->toBe('Site default');
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

	test('socialTitle: the card, else the raw title; the social template shapes only the derived title, never the title template', function () use ($b): void {
		$p = $b->build(seoCtx(['fields' => SeoFields::fromArray(['socialTitle' => ' Short '])]));
		expect($p->socialTitle)->toBe('Short')->and($p->title)->toBe('Hello <World> | Bistro')->and($p->rawTitle)->toBe('Hello <World>');

		// The default social template is the bare `{title}`: no site suffix
		// on a share card, exactly what a card with no Social Title got before.
		expect($b->build(seoCtx())->socialTitle)->toBe('Hello <World>');

		// A site-level Social Title Template structures share titles on its
		// own, independent of <title> — for the derived title. A Social Title
		// someone typed on the card is used as typed, like the card's Title.
		$settings = SeoSettings::fromArray(['socialTitleTemplate' => '${title} — from ${site}'], 'x');
		$p        = $b->build(seoCtx(['settings' => $settings]));
		expect($p->socialTitle)->toBe('Hello <World> — from Bistro')->and($p->title)->toBe('Hello <World> | Bistro');
		expect($b->build(seoCtx(['settings' => $settings, 'fields' => SeoFields::fromArray(['socialTitle' => 'Short'])]))->socialTitle)->toBe('Short');

		// A title shaped by the card or the collection template is already
		// authored: the share card carries it as is, with no social template.
		$block = ['type' => '', 'title' => 'YETI - ${title}', 'socialTitle' => '', 'description' => '', 'image' => ''];
		expect($b->build(seoCtx(['settings' => $settings, 'seoBlock' => $block]))->socialTitle)->toBe('YETI - Hello <World>');
		expect($b->build(seoCtx(['settings' => $settings, 'fields' => SeoFields::fromArray(['title' => 'Custom'])]))->socialTitle)->toBe('Custom');

		// No record behind the page: the share title collapses to the site
		// name rather than a dangling template.
		expect($b->build(seoCtx(['settings' => $settings, 'kind' => 'none', 'object' => [], 'url' => '']))->socialTitle)->toBe('Bistro');
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

	test('description: only a mapped property is capped — card and site default pass through verbatim', function () use ($b): void {
		$long = str_repeat('word ', 60); // 300 characters
		expect($b->build(seoCtx(['fields' => SeoFields::fromArray(['description' => $long])]))->description)->toBe(trim($long));
		expect($b->build(seoCtx(['seoBlock' => ['type' => '', 'title' => '', 'socialTitle' => '', 'description' => '', 'image' => ''], 'settings' => SeoSettings::fromArray(['defaultDescription' => $long], 'x')]))->description)->toBe(trim($long));
		expect(mb_strlen($b->build(seoCtx(['object' => ['id' => 'x', 'title' => 't', 'summary' => $long]]))->description))->toBeLessThanOrEqual(160);
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
		expect($b->build(seoCtx(['seoBlock' => ['type' => '', 'title' => '', 'socialTitle' => '', 'description' => '', 'image' => '']]))->ogType)->toBe('website');
		// An explicit `website` on an otherwise-article context opts out.
		expect($b->build(seoCtx(['seoBlock' => ['type' => 'website', 'title' => '', 'socialTitle' => '', 'description' => 'summary', 'image' => 'image']]))->ogType)->toBe('website');
		expect($b->build(seoCtx(['kind' => 'none', 'object' => [], 'url' => '']))->canonical)->toBe('');
	});
});
