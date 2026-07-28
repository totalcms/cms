<?php

declare(strict_types=1);

use TotalCMS\Domain\XmlRpc\Service\PostMapper;

require_once __DIR__ . '/XmlRpcUnitHelpers.php';

describe('toObject', function (): void {
	it('maps the WordPress struct onto blog fields', function (): void {
		$fields = makePostMapper()->toObject([
			'title'        => 'Hello World',
			'description'  => '<p>Body</p>',
			'mt_excerpt'   => 'Short',
			'mt_text_more' => '<p>Extended</p>',
			'mt_keywords'  => 'php, cms , flat-file',
			'categories'   => ['Tech', 'PHP'],
			'sticky'       => true,
		], publish: true, isNew: true);

		expect($fields['title'])->toBe('Hello World');
		expect($fields['content'])->toBe('<p>Body</p>');
		expect($fields['summary'])->toBe('Short');
		expect($fields['extra'])->toBe('<p>Extended</p>');
		expect($fields['tags'])->toBe(['php', 'cms', 'flat-file']);
		expect($fields['categories'])->toBe(['Tech', 'PHP']);
		expect($fields['featured'])->toBeTrue();
		expect($fields['draft'])->toBeFalse();
	});

	it('returns only the keys the client sent', function (): void {
		// This is what protects an admin-set hero image from a text-only edit:
		// absent keys must never appear, so patching cannot clear them.
		$fields = makePostMapper()->toObject(['title' => 'Only a title'], publish: true, isNew: false);

		expect(array_keys($fields))->toBe(['title', 'draft']);
		expect($fields)->not->toHaveKey('image');
		expect($fields)->not->toHaveKey('gallery');
	});

	it('maps every WordPress status', function (): void {
		$mapper = makePostMapper();

		expect($mapper->toObject(['post_status' => 'publish'], true, false)['draft'])->toBeFalse();
		expect($mapper->toObject(['post_status' => 'draft'], true, false)['draft'])->toBeTrue();
		expect($mapper->toObject(['post_status' => 'pending'], true, false)['draft'])->toBeTrue();
		// No private-post concept: stay hidden rather than accidentally public.
		expect($mapper->toObject(['post_status' => 'private'], true, false)['draft'])->toBeTrue();
		// Core does not auto-publish on a date, so a scheduled post stays a draft
		// with its future date preserved.
		expect($mapper->toObject(['post_status' => 'future'], true, false)['draft'])->toBeTrue();
	});

	it('honours the publish flag when no status is sent', function (): void {
		expect(makePostMapper()->toObject(['title' => 'x'], publish: false, isNew: true)['draft'])->toBeTrue();
	});

	it('accepts wp_slug on create and ignores it on edit', function (): void {
		$mapper = makePostMapper();

		expect($mapper->toObject(['wp_slug' => 'my-post'], true, isNew: true)['id'])->toBe('my-post');
		// The id IS the storage location — uploaded files live under it and it
		// drives the public URL, so a client cannot rename a post.
		expect($mapper->toObject(['wp_slug' => 'renamed'], true, isNew: false))->not->toHaveKey('id');
	});

	it('never maps thumbnail, password, comment or custom fields', function (): void {
		$fields = makePostMapper()->toObject([
			'title'             => 'x',
			'wp_post_thumbnail' => '',
			'wp_password'       => 'secret',
			'mt_allow_comments' => 1,
			'mt_allow_pings'    => 1,
			'custom_fields'     => [['key' => 'k', 'value' => 'v']],
		], true, false);

		expect(array_keys($fields))->toBe(['title', 'draft']);
	});

	it('prefers the GMT date and converts to the site timezone', function (): void {
		$mapper = makePostMapper('America/Los_Angeles');

		$fields = $mapper->toObject([
			'dateCreated'      => new DateTimeImmutable('2026-07-28 09:00:00'),
			'date_created_gmt' => new DateTimeImmutable('2026-07-28 16:00:00', new DateTimeZone('UTC')),
		], true, true);

		// 16:00 UTC is 09:00 Pacific — proving the GMT field won and was converted.
		expect($fields['date'])->toStartWith('2026-07-28T09:00:00');
	});

	it('relativizes our own upload URLs before storing', function (): void {
		$html = '<img src="https://demo.test/tcms/imageworks/upload/blog/p1/content/a.jpg">';

		expect(makePostMapper()->toObject(['description' => $html], true, false)['content'])
			->toBe('<img src="/tcms/imageworks/upload/blog/p1/content/a.jpg">');
	});

	it('sets categories to an empty list rather than dropping the key when the value is not an array', function (): void {
		// mt_keywords: null already yields tags => [] (splitKeywords casts to
		// string). categories must behave the same way — present-but-unusable,
		// not absent — rather than vanishing like an unsent key would.
		$fields = makePostMapper()->toObject(['categories' => null], true, false);

		expect($fields)->toHaveKey('categories');
		expect($fields['categories'])->toBe([]);
	});

	it('prefers the GMT date when both arrive as plain strings, and converts to the site timezone', function (): void {
		$mapper = makePostMapper('America/Los_Angeles');

		$fields = $mapper->toObject([
			'dateCreated'      => '2026-07-28T01:00:00+00:00',
			'date_created_gmt' => '2026-07-28T16:00:00+00:00',
		], true, true);

		// 16:00 UTC is 09:00 Pacific — proving the GMT string won over the
		// dateCreated string (01:00 UTC / 18:00 the previous day Pacific) and
		// was converted to the site timezone.
		expect($fields['date'])->toStartWith('2026-07-28T09:00:00');
	});

	it('falls back to the dateCreated string when no GMT field is sent', function (): void {
		$mapper = makePostMapper('America/Los_Angeles');

		$fields = $mapper->toObject(['dateCreated' => '2026-07-28T16:00:00+00:00'], true, true);

		expect($fields['date'])->toStartWith('2026-07-28T09:00:00');
	});

	it('never throws out of a malformed date string', function (): void {
		// PHP's DateTimeImmutable is inconsistent across versions here: some
		// releases throw on garbage input, others silently build a bogus date.
		// The mapper must be safe either way — assert only that toObject()
		// itself never lets that exception escape.
		$mapper = makePostMapper();

		expect(fn (): array => $mapper->toObject(['date_created_gmt' => 'not-a-date'], true, true))
			->not->toThrow(Throwable::class);
	});
});

describe('toStruct', function (): void {
	it('builds the full WordPress struct', function (): void {
		$struct = makePostMapper()->toStruct([
			'id'         => 'hello-world',
			'title'      => 'Hello World',
			'content'    => '<p>Body</p>',
			'summary'    => 'Short',
			'extra'      => '<p>More</p>',
			'tags'       => ['php', 'cms'],
			'categories' => ['Tech'],
			'draft'      => false,
			'featured'   => true,
			'author'     => 'Joe Workman',
			'date'       => '2026-07-28T09:00:00-07:00',
		], blogCollection('blog'));

		expect($struct['postid'])->toBe('hello-world');
		expect($struct['wp_slug'])->toBe('hello-world');
		expect($struct['title'])->toBe('Hello World');
		expect($struct['mt_excerpt'])->toBe('Short');
		expect($struct['mt_text_more'])->toBe('<p>More</p>');
		expect($struct['mt_keywords'])->toBe('php, cms');
		expect($struct['categories'])->toBe(['Tech']);
		expect($struct['post_status'])->toBe('publish');
		expect($struct['sticky'])->toBeTrue();
		expect($struct['wp_author_display_name'])->toBe('Joe Workman');
		expect($struct['dateCreated'])->toBeInstanceOf(DateTimeImmutable::class);
		expect($struct['date_created_gmt'])->toBeInstanceOf(DateTimeImmutable::class);
	});

	it('reports drafts as draft status', function (): void {
		expect(makePostMapper()->toStruct(['id' => 'p', 'draft' => true], blogCollection('blog'))['post_status'])
			->toBe('draft');
	});

	it('absolutizes upload URLs on read so client previews resolve', function (): void {
		$struct = makePostMapper()->toStruct([
			'id'      => 'p1',
			'content' => '<img src="/tcms/imageworks/upload/blog/p1/content/a.jpg">',
		], blogCollection('blog'));

		expect($struct['description'])->toContain('https://demo.test/tcms/imageworks/upload/');
	});

	it('is idempotent when content already holds absolute URLs', function (): void {
		$mapper = makePostMapper();
		$html   = '<img src="https://demo.test/tcms/imageworks/upload/blog/p1/content/a.jpg">';

		expect($mapper->absolutizeUrls($html))->toBe($html);
		expect($mapper->absolutizeUrls($mapper->absolutizeUrls($html)))->toBe($html);
	});

	it('never throws building a struct from a malformed stored date', function (): void {
		$mapper = makePostMapper();

		expect(fn (): array => $mapper->toStruct(['id' => 'p', 'date' => 'not-a-date'], blogCollection('blog')))
			->not->toThrow(Throwable::class);
	});

	/*
	 * A third-party absolute URL that happens to contain our own upload path
	 * as a substring further in (not at the start of the attribute value) must
	 * never be rewritten — that's exactly the naive-str_replace bug that would
	 * mangle it into a double-host URL. This pins the quote-anchoring fix for
	 * the subpath mapper; the sibling root-domain describe block below pins
	 * the same protection where the search string is at its most generic.
	 */
	it('leaves a third-party URL that merely contains our upload path untouched', function (): void {
		$mapper = makePostMapper();
		$html   = '<img src="https://othersite.com/tcms/imageworks/upload/pic.jpg">';

		expect($mapper->absolutizeUrls($html))->toBe($html);
		expect($mapper->relativizeUrls($html))->toBe($html);
	});
});

describe('root-domain installs (no path component in config->api)', function (): void {
	it('relativizes our own upload URLs to root-relative form', function (): void {
		$mapper = makePostMapper(api: 'https://demo.test');
		$html   = '<img src="https://demo.test/imageworks/upload/blog/p1/content/a.jpg">';

		expect($mapper->relativizeUrls($html))->toBe('<img src="/imageworks/upload/blog/p1/content/a.jpg">');
	});

	it('absolutizes our own root-relative upload URLs', function (): void {
		$mapper = makePostMapper(api: 'https://demo.test');
		$html   = '<img src="/imageworks/upload/blog/p1/content/a.jpg">';

		expect($mapper->absolutizeUrls($html))
			->toBe('<img src="https://demo.test/imageworks/upload/blog/p1/content/a.jpg">');
	});

	it('is idempotent when content already holds absolute URLs', function (): void {
		$mapper = makePostMapper(api: 'https://demo.test');
		$html   = '<img src="https://demo.test/imageworks/upload/blog/p1/content/a.jpg">';

		expect($mapper->absolutizeUrls($html))->toBe($html);
		expect($mapper->absolutizeUrls($mapper->absolutizeUrls($html)))->toBe($html);
	});

	/*
	 * This is the case the quote-anchoring specifically protects: with an
	 * empty path base the search string is as generic as "/imageworks/upload/",
	 * which a completely unrelated site's URL can easily contain further in
	 * (not at the start). Without anchoring on the opening quote, this would
	 * get corrupted into a mangled double-host URL.
	 */
	it('leaves an upload-shaped URL on a different host completely untouched', function (): void {
		$mapper = makePostMapper(api: 'https://demo.test');
		$html   = '<img src="https://othersite.com/imageworks/upload/pic.jpg">';

		expect($mapper->absolutizeUrls($html))->toBe($html);
		expect($mapper->relativizeUrls($html))->toBe($html);
	});
});
