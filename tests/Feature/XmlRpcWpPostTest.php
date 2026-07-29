<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\XmlRpc\Service\MethodRouter;

/**
 * The `wp.*` post family — MarsEdit's "WordPress API" system, which speaks
 * this dialect exclusively and never falls back to metaWeblog. Its first
 * call is wp.getPosts, so this family has to work end to end for a client
 * that never sends a single metaWeblog.* method.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	enableXmlRpc();
	$this->app->getContainer()
		->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)
		->fetchOrCreateReserved('blog');
});

/** Escapes a raw XML-RPC struct member's string value. */
function wpXmlEscape(string $value): string
{
	return htmlspecialchars($value, ENT_XML1, 'UTF-8');
}

/** @param array<int,string> $names */
function wpTermsArray(array $names): string
{
	$inner = '';
	foreach ($names as $name) {
		$inner .= '<value><string>' . wpXmlEscape($name) . '</string></value>';
	}

	return '<array><data>' . $inner . '</data></array>';
}

it('registers every wp.* post method', function (): void {
	$names = $this->app->getContainer()->get(MethodRouter::class)->methodNames();

	foreach ([
		'wp.getPosts', 'wp.getPost', 'wp.newPost', 'wp.editPost', 'wp.deletePost',
		'wp.getPostTypes', 'wp.getPostStatusList', 'wp.getTaxonomies', 'wp.getTerms',
		'wp.getMediaLibrary', 'wp.getMediaItem',
	] as $method) {
		expect($names)->toContain($method);
	}
});

it('returns posts newest first from wp.getPosts and honours number/offset paging', function (): void {
	$container = $this->app->getContainer();
	$saver     = $container->get(ObjectSaver::class);

	// Deliberately not alphabetical-order-friendly ids, same discipline as
	// XmlRpcPostReadTest: date order and id order disagree, so a regression to
	// id-sort or to ignoring offset cannot pass by accident.
	foreach (
		[
			'wp-post-a' => '2026-07-27T09:00:00Z', // newest
			'wp-post-b' => '2026-07-20T09:00:00Z',
			'wp-post-c' => '2026-07-15T09:00:00Z',
			'wp-post-d' => '2026-07-10T09:00:00Z', // oldest
		] as $id => $date
	) {
		$saver->saveObject('blog', ['id' => $id, 'title' => 'Title ' . $id, 'date' => $date, 'draft' => false]);
	}

	$key = xmlRpcKey();

	$firstPage = (string)postXmlRpc(xmlRpcBody(
		'wp.getPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['number' => '2', 'offset' => '0'])
	))->getBody();

	expect($firstPage)->not->toContain('<fault>');
	expect($firstPage)->toContain('wp-post-a');
	expect($firstPage)->toContain('wp-post-b');
	expect($firstPage)->not->toContain('wp-post-c');
	expect($firstPage)->not->toContain('wp-post-d');
	expect(strpos($firstPage, 'wp-post-a'))->toBeLessThan((int)strpos($firstPage, 'wp-post-b'));

	$secondPage = (string)postXmlRpc(xmlRpcBody(
		'wp.getPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['number' => '2', 'offset' => '2'])
	))->getBody();

	expect($secondPage)->not->toContain('<fault>');
	// The second page must be genuinely different from the first.
	expect($secondPage)->toContain('wp-post-c');
	expect($secondPage)->toContain('wp-post-d');
	expect($secondPage)->not->toContain('wp-post-a');
	expect($secondPage)->not->toContain('wp-post-b');
});

it('filters wp.getPosts by post_status', function (): void {
	$container = $this->app->getContainer();
	$saver     = $container->get(ObjectSaver::class);
	$saver->saveObject('blog', ['id' => 'wp-published', 'title' => 'Published one', 'draft' => false]);
	$saver->saveObject('blog', ['id' => 'wp-drafted', 'title' => 'Drafted one', 'draft' => true]);

	$key = xmlRpcKey();

	$publishedOnly = (string)postXmlRpc(xmlRpcBody(
		'wp.getPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['post_status' => 'publish'])
	))->getBody();

	expect($publishedOnly)->toContain('wp-published');
	expect($publishedOnly)->not->toContain('wp-drafted');

	$draftsOnly = (string)postXmlRpc(xmlRpcBody(
		'wp.getPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['post_status' => 'draft'])
	))->getBody();

	expect($draftsOnly)->toContain('wp-drafted');
	expect($draftsOnly)->not->toContain('wp-published');

	$both = (string)postXmlRpc(xmlRpcBody(
		'wp.getPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($both)->toContain('wp-published');
	expect($both)->toContain('wp-drafted');
});

it('returns a full wp.getPost struct including terms', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'         => 'wp-full-post',
		'title'      => 'Full post',
		'content'    => '<p>Body</p>',
		'summary'    => 'A summary',
		'categories' => ['Tech', 'PHP'],
		'tags'       => ['flat-file'],
		'draft'      => false,
	]);

	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-full-post')
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>post_id</name><value><string>wp-full-post</string></value>');
	expect($body)->toContain('<name>post_title</name><value><string>Full post</string></value>');
	expect($body)->toContain('Body');
	expect($body)->toContain('<name>post_status</name><value><string>publish</string></value>');
	expect($body)->toContain('<name>post_type</name><value><string>post</string></value>');
	// One term struct per category/tag, correctly tagged by taxonomy.
	expect(substr_count($body, '<name>term_id</name><value><string>Tech</string></value>'))->toBe(1);
	expect(substr_count($body, '<name>term_id</name><value><string>PHP</string></value>'))->toBe(1);
	expect($body)->toContain('<name>taxonomy</name><value><string>category</string></value>');
	expect($body)->toContain('<name>taxonomy</name><value><string>post_tag</string></value>');
	expect($body)->toContain('<name>term_id</name><value><string>flat-file</string></value>');
});

it('faults wp.getPost with 404 for a post that does not exist', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('no-such-post')
	))->getBody();

	expect($body)->toContain('<int>404</int>');
});

it('creates a post via wp.newPost with title, content, excerpt, status and terms_names', function (): void {
	$key = xmlRpcKey();

	$struct = '<param><value><struct>'
		. '<member><name>post_title</name><value><string>' . wpXmlEscape('Writing from MarsEdit') . '</string></value></member>'
		. '<member><name>post_content</name><value><string>' . wpXmlEscape('<p>Body text.</p>') . '</string></value></member>'
		. '<member><name>post_excerpt</name><value><string>' . wpXmlEscape('An excerpt.') . '</string></value></member>'
		. '<member><name>post_status</name><value><string>draft</string></value></member>'
		. '<member><name>terms_names</name><value><struct>'
		. '<member><name>category</name><value>' . wpTermsArray(['Tech']) . '</value></member>'
		. '<member><name>post_tag</name><value>' . wpTermsArray(['php', 'wordpress']) . '</value></member>'
		. '</struct></value></member>'
		. '</struct></value></param>';

	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . $struct
	))->getBody();

	expect($body)->not->toContain('<fault>');
	// Slugified from post_title, same convention metaWeblog.newPost uses.
	expect($body)->toContain('writing-from-marsedit');

	$object = $this->app->getContainer()->get(ObjectFetcher::class)
		->fetchObject('blog', 'writing-from-marsedit')->toArray();

	expect($object['title'])->toBe('Writing from MarsEdit');
	expect($object['content'])->toContain('Body text.');
	expect($object['summary'])->toBe('An excerpt.');
	expect($object['draft'])->toBeTrue();
	expect($object['categories'])->toBe(['Tech']);
	expect($object['tags'])->toBe(['php', 'wordpress']);
});

it('preserves an admin-set image when wp.editPost only changes the title', function (): void {
	// THE regression this whole family exists to avoid repeating: WordPress's
	// struct has no concept of `image`, so a title-only edit through this
	// dialect must not touch it either. Mirrors the metaWeblog guard exactly.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'       => 'wp-has-image',
		'title'    => 'Original title',
		'content'  => '<p>Original body</p>',
		'summary'  => 'Original summary',
		'image'    => ['name' => 'hero.jpg', 'alt' => 'Hero shot'],
		'featured' => true,
	]);

	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-has-image')
		. xmlRpcStructParam(['post_title' => 'New title'])
	))->getBody();

	expect($body)->not->toContain('<fault>');

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'wp-has-image')->toArray();

	expect($object['title'])->toBe('New title');
	expect($object['content'])->toBe('<p>Original body</p>');
	expect($object['summary'])->toBe('Original summary');
	expect($object['image']['name'] ?? null)->toBe('hero.jpg');
	expect($object['image']['alt'] ?? null)->toBe('Hero shot');
	expect($object['featured'])->toBeTrue();
});

it('never renames a post when wp.editPost sends a different post_name', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'wp-keep-id', 'title' => 'Keep this id']);

	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-keep-id')
		. xmlRpcStructParam(['post_title' => 'Renamed', 'post_name' => 'brand-new-id'])
	));

	$fetcher = $container->get(ObjectFetcher::class);
	expect($fetcher->existsObject('blog', 'wp-keep-id'))->toBeTrue();
	expect($fetcher->existsObject('blog', 'brand-new-id'))->toBeFalse();
	expect($fetcher->fetchObject('blog', 'wp-keep-id')->toArray()['title'])->toBe('Renamed');
});

it('round-trips an extended entry through the <!--more--> marker', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'wp-extended',
		'title'   => 'Extended entry',
		'content' => '<p>Main body.</p>',
		'extra'   => '<p>The rest.</p>',
	]);

	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-extended')
	))->getBody();

	// The response body is XML-escaped, so the marker itself is rendered as
	// &lt;!--more--&gt; — assert the pieces are present, in order, joined by it.
	expect($body)->toContain('Main body.&lt;/p&gt;&lt;!--more--&gt;&lt;p&gt;The rest.');

	// Writing that same combined post_content back must preserve both halves.
	postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-extended')
		. xmlRpcStructParam(['post_content' => '<p>Main body.</p><!--more--><p>The rest.</p>'])
	));

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'wp-extended')->toArray();
	expect($object['content'])->toBe('<p>Main body.</p>');
	expect($object['extra'])->toBe('<p>The rest.</p>');
});

it('splits post_content on every marker variant WordPress supports, on an edit to a post that already has an extended entry', function (): void {
	// WordPress recognizes more than the bare literal: whitespace inside the
	// comment (`<!-- more -->`) and a teaser variant with text after the
	// keyword (`<!--more Read on-->`) both count. The split only fires on
	// wp.editPost when the post being edited already has a non-empty `extra`
	// — see PostMapper::fromWpStruct() — so these posts are pre-seeded with a
	// placeholder extended entry before the edit that actually exercises the
	// marker-variant parsing.
	$container = $this->app->getContainer();
	$saver     = $container->get(ObjectSaver::class);
	$fetcher   = $container->get(ObjectFetcher::class);
	$key       = xmlRpcKey();

	$cases = [
		'wp-marker-bare'   => '<p>Body one.</p><!--more--><p>Extra one.</p>',
		'wp-marker-spaced' => '<p>Body two.</p><!-- more --><p>Extra two.</p>',
		'wp-marker-teaser' => '<p>Body three.</p><!--more Read on--><p>Extra three.</p>',
	];

	foreach ($cases as $id => $postContent) {
		$saver->saveObject('blog', ['id' => $id, 'title' => $id, 'content' => 'placeholder', 'extra' => 'placeholder']);

		$body = (string)postXmlRpc(xmlRpcBody(
			'wp.editPost',
			xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam($id)
			. xmlRpcStructParam(['post_content' => $postContent])
		))->getBody();
		expect($body)->not->toContain('<fault>');
	}

	$bare = $fetcher->fetchObject('blog', 'wp-marker-bare')->toArray();
	expect($bare['content'])->toBe('<p>Body one.</p>');
	expect($bare['extra'])->toBe('<p>Extra one.</p>');

	$spaced = $fetcher->fetchObject('blog', 'wp-marker-spaced')->toArray();
	expect($spaced['content'])->toBe('<p>Body two.</p>');
	expect($spaced['extra'])->toBe('<p>Extra two.</p>');

	$teaser = $fetcher->fetchObject('blog', 'wp-marker-teaser')->toArray();
	expect($teaser['content'])->toBe('<p>Body three.</p>');
	expect($teaser['extra'])->toBe('<p>Extra three.</p>');
});

it('round-trips a teaser marker\'s content/extra split, but normalizes the marker wording on read', function (): void {
	// The split itself (which text lands in `content` vs. `extra`) round-trips
	// exactly. The marker's own wording does not: `extra` is shared verbatim
	// with the metaWeblog dialect's mt_text_more and the admin's own Extended
	// Entry editor, and there is no field to stash "Read on" (or the original
	// whitespace) in without a schema change — so every read normalizes back
	// to the bare `<!--more-->` form. This is a deliberate tradeoff, not a bug.
	//
	// The edit's split only fires because this post already has an extended
	// entry (a placeholder, seeded below) — see PostMapper::fromWpStruct().
	$container = $this->app->getContainer();
	$key       = xmlRpcKey();

	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'wp-teaser-round-trip',
		'title'   => 'Teaser round trip',
		'content' => 'placeholder',
		'extra'   => 'placeholder',
	]);

	postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-teaser-round-trip')
		. xmlRpcStructParam([
			'post_content' => '<p>Main body.</p><!--more Read on--><p>Teaser body.</p>',
		])
	));

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'wp-teaser-round-trip')->toArray();
	expect($object['content'])->toBe('<p>Main body.</p>');
	expect($object['extra'])->toBe('<p>Teaser body.</p>');

	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-teaser-round-trip')
	))->getBody();

	expect($body)->not->toContain('Read on');
	expect($body)->toContain('Main body.&lt;/p&gt;&lt;!--more--&gt;&lt;p&gt;Teaser body.');
});

it('splits post_content on the first marker only when it contains two', function (): void {
	$container = $this->app->getContainer();
	$key       = xmlRpcKey();

	// The split only fires on an edit to a post that already has an extended
	// entry (see PostMapper::fromWpStruct()), so this post is seeded with a
	// placeholder one before the edit that exercises the first-marker-only rule.
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'wp-two-markers',
		'title'   => 'Two markers',
		'content' => 'placeholder',
		'extra'   => 'placeholder',
	]);

	postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-two-markers')
		. xmlRpcStructParam([
			'post_content' => '<p>First.</p><!--more--><p>Middle.</p><!--more--><p>Last.</p>',
		])
	));

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'wp-two-markers')->toArray();
	expect($object['content'])->toBe('<p>First.</p>');
	// The second marker's literal text is NOT re-split on — it survives
	// untouched as part of the teaser body.
	expect($object['extra'])->toBe('<p>Middle.</p><!--more--><p>Last.</p>');
});

it('leaves an existing extra untouched when wp.editPost sends post_content with no marker', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'wp-no-marker',
		'title'   => 'No marker',
		'content' => '<p>Old body.</p>',
		'extra'   => '<p>Untouched extra.</p>',
	]);

	$key = xmlRpcKey();
	postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-no-marker')
		. xmlRpcStructParam(['post_content' => '<p>New body, no marker.</p>'])
	));

	$object = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('blog', 'wp-no-marker')->toArray();
	expect($object['content'])->toBe('<p>New body, no marker.</p>');
	expect($object['extra'])->toBe('<p>Untouched extra.</p>');
});

it('stores the whole body in content and sets no extra when wp.newPost sends post_content with a marker', function (): void {
	// A create has no existing post to consult for "does this one already have
	// an extended entry", so the split never fires here — the whole body is
	// stored as `content`, matching both how WordPress itself stores an inline
	// marker and what WordpressImporter does for an imported post.
	$key = xmlRpcKey();

	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam([
			'post_title'   => 'New with marker',
			'post_name'    => 'wp-new-with-marker',
			'post_content' => '<p>Teaser.</p><!--more--><p>Rest of body.</p>',
		])
	))->getBody();
	expect($body)->not->toContain('<fault>');

	$object = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('blog', 'wp-new-with-marker')->toArray();
	expect($object['content'])->toBe('<p>Teaser.</p><!--more--><p>Rest of body.</p>');
	expect($object['extra'] ?? '')->toBe('');
});

it('leaves an imported post\'s inline <!--more--> marker alone when wp.editPost only changes the title', function (): void {
	// This is the regression this whole fix exists for: WordpressImporter
	// stores WXR content:encoded verbatim into `content` and never populates
	// `extra`, so an imported post can carry an inline <!--more--> with `extra`
	// empty. A WordPress-API client (e.g. MarsEdit) echoes the post's full,
	// unchanged post_content back on every edit, even a title-only one. Before
	// this fix, that inline marker was split unconditionally, truncating
	// `content` to the teaser and silently disappearing everything after the
	// marker from any template that renders only `content`.
	$container    = $this->app->getContainer();
	$importedBody = '<p>Teaser from WordPress.</p><!--more--><p>The rest of the imported body.</p>';

	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'wp-imported-post',
		'title'   => 'Imported post',
		'content' => $importedBody,
		// No `extra` at all — exactly what WordpressImporter leaves behind.
	]);

	$key   = xmlRpcKey();
	$fault = (string)postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-imported-post')
		// A real client sends the full struct back, including the unchanged
		// post_content, on a title-only edit — this is not a contrived input.
		. xmlRpcStructParam(['post_title' => 'Imported post, retitled', 'post_content' => $importedBody])
	))->getBody();
	expect($fault)->not->toContain('<fault>');

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'wp-imported-post')->toArray();
	expect($object['title'])->toBe('Imported post, retitled');
	expect($object['content'])->toBe($importedBody);
	expect($object['extra'] ?? '')->toBe('');
});

it('lists distinct categories from wp.getTerms and faults on an unknown taxonomy', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'wp-terms-one', 'title' => 'One', 'categories' => ['Tech', 'PHP']]);
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'wp-terms-two', 'title' => 'Two', 'categories' => ['Tech']]);

	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getTerms',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('category')
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>term_id</name><value><string>Tech</string></value>');
	expect($body)->toContain('<name>term_id</name><value><string>PHP</string></value>');
	expect(substr_count($body, '<name>taxonomy</name><value><string>category</string></value>'))->toBe(2);

	$unknown = (string)postXmlRpc(xmlRpcBody(
		'wp.getTerms',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('unknown-taxonomy')
	))->getBody();

	expect($unknown)->toContain('<fault>');
});

it('reports the two taxonomies wp.getTaxonomies advertises', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getTaxonomies',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>name</name><value><string>category</string></value>');
	expect($body)->toContain('<name>name</name><value><string>post_tag</string></value>');
});

it('reports only draft and publish from wp.getPostStatusList', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getPostStatusList',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('Draft');
	expect($body)->toContain('Published');
	expect($body)->not->toContain('Pending');
	expect($body)->not->toContain('Private');
	expect($body)->not->toContain('Future');
});

it('reports the single post type from wp.getPostTypes', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getPostTypes',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>name</name><value><string>post</string></value>');
	expect($body)->toContain('<name>label</name><value><string>Posts</string></value>');
});

it('lets a GET-only key read via wp.getPosts and wp.getPost but refuses wp.newPost and wp.editPost', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'wp-scope-post', 'title' => 'Scope test']);

	$key = xmlRpcKey(['blog'], ['GET']);

	$listBody = (string)postXmlRpc(xmlRpcBody(
		'wp.getPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();
	expect($listBody)->not->toContain('<fault>');
	expect($listBody)->toContain('wp-scope-post');

	$getBody = (string)postXmlRpc(xmlRpcBody(
		'wp.getPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-scope-post')
	))->getBody();
	expect($getBody)->not->toContain('<fault>');

	$newBody = (string)postXmlRpc(xmlRpcBody(
		'wp.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['post_title' => 'Should not land'])
	))->getBody();
	expect($newBody)->toContain('<int>401</int>');
	expect($container->get(ObjectFetcher::class)->existsObject('blog', 'should-not-land'))->toBeFalse();

	$editBody = (string)postXmlRpc(xmlRpcBody(
		'wp.editPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('wp-scope-post')
		. xmlRpcStructParam(['post_title' => 'Should not change'])
	))->getBody();
	expect($editBody)->toContain('<int>401</int>');
	expect($container->get(ObjectFetcher::class)->fetchObject('blog', 'wp-scope-post')->toArray()['title'])
		->toBe('Scope test');
});

it('registers wp.getMediaLibrary and wp.getMediaItem on the unsupported handler', function (): void {
	$key = xmlRpcKey();

	foreach (['wp.getMediaLibrary', 'wp.getMediaItem'] as $method) {
		$body = (string)postXmlRpc(xmlRpcBody(
			$method,
			xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		))->getBody();

		expect($body)->toContain('<fault>');
		expect($body)->toContain('<int>401</int>');
		expect($body)->toContain('media uploads');
	}
});
