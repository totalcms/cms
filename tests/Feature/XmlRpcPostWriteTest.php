<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

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

it('publishes a post and stores every mapped field', function (): void {
	$key = xmlRpcKey();

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam([
			'title'        => 'Writing from a client',
			'description'  => '<p>Body text.</p>',
			'mt_excerpt'   => 'A summary.',
			'mt_text_more' => '<p>Extended.</p>',
			'mt_keywords'  => 'php, publishing',
			'categories'   => ['Tech'],
			'wp_slug'      => 'writing-from-a-client',
		])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('writing-from-a-client');

	$object = $this->app->getContainer()->get(ObjectFetcher::class)
		->fetchObject('blog', 'writing-from-a-client')->toArray();

	expect($object['title'])->toBe('Writing from a client');
	expect($object['content'])->toContain('Body text.');
	expect($object['summary'])->toBe('A summary.');
	expect($object['extra'])->toContain('Extended.');
	expect($object['tags'])->toBe(['php', 'publishing']);
	expect($object['categories'])->toBe(['Tech']);
	expect($object['draft'])->toBeFalse();
	// Author comes from the resolved username, falling back to the key name.
	expect($object['author'])->not->toBe('');
});

it('publishes an unpublished post as a draft', function (): void {
	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Not ready', 'wp_slug' => 'not-ready'])
		. xmlRpcBoolParam(false)
	));

	$object = $this->app->getContainer()->get(ObjectFetcher::class)
		->fetchObject('blog', 'not-ready')->toArray();

	expect($object['draft'])->toBeTrue();
});

it('creates as published when no publish flag is sent at all', function (): void {
	// newPost's default (no fifth param) is still "publish" — that is
	// WordPress's behavior for creates and must not change.
	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'No flag at all', 'wp_slug' => 'no-flag-at-all'])
	));

	$object = $this->app->getContainer()->get(ObjectFetcher::class)
		->fetchObject('blog', 'no-flag-at-all')->toArray();

	expect($object['draft'])->toBeFalse();
});

it('refuses to write without a valid key', function (): void {
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam('tcms_not_real')
		. xmlRpcStructParam(['title' => 'Should not exist', 'description' => '<p>x</p>'])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->toContain('<int>403</int>');
	expect($this->app->getContainer()->get(ObjectFetcher::class)->existsObject('blog', 'should-not-exist'))
		->toBeFalse();
});

it('refuses to write with a read-only key', function (): void {
	// Method scopes become RPC capabilities, so a GET-only key cannot publish.
	// The key still authenticates (XmlRpcAuth::authenticate() only checks the
	// path grant now — the transport is always HTTP POST regardless of RPC
	// verb, so it must not consume the caller's POST grant), and is refused at
	// assertOperation()'s RPC-level check instead: 401, not a credential fault.
	$key = xmlRpcKey(['blog'], ['GET']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.newPost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Read only', 'wp_slug' => 'read-only'])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->toContain('<int>401</int>');
	expect($this->app->getContainer()->get(ObjectFetcher::class)->existsObject('blog', 'read-only'))
		->toBeFalse();
});

it('preserves an admin-set image when an edit only changes text', function (): void {
	// THE regression this design turns on. ObjectUpdater::updateObject() REPLACES
	// an object, so routing an edit through it would wipe every field WordPress
	// knows nothing about. Driven through the real endpoint, not a private method.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'       => 'has-image',
		'title'    => 'Original title',
		'content'  => '<p>Original body</p>',
		'summary'  => 'Original summary',
		'image'    => ['name' => 'hero.jpg', 'alt' => 'Hero shot'],
		'featured' => true,
	]);

	$key = xmlRpcKey();

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('has-image') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'New title'])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->not->toContain('<fault>');

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'has-image')->toArray();

	expect($object['title'])->toBe('New title');
	expect($object['content'])->toBe('<p>Original body</p>');
	expect($object['summary'])->toBe('Original summary');
	expect($object['image']['name'] ?? null)->toBe('hero.jpg');
	expect($object['image']['alt'] ?? null)->toBe('Hero shot');
	expect($object['featured'])->toBeTrue();
});

it('leaves a draft alone when an edit sends only a title and no publish flag', function (): void {
	// THE regression this fix round guards. metaWeblog.editPost's fifth param
	// is a deliberate publish/draft switch, but when a client omits it
	// entirely (a genuinely 4-param call), the mapper must not invent
	// "publish" just because that is newPost's default — the post's current
	// draft state must survive untouched.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'stays-draft',
		'title'   => 'Original title',
		'content' => '<p>Original body</p>',
		'draft'   => true,
	]);

	$key = xmlRpcKey();

	// Deliberately only 4 params: postid, username, password, struct — no
	// fifth (publish) param at all.
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('stays-draft') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'New title, still a draft'])
	))->getBody();

	expect($body)->not->toContain('<fault>');

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'stays-draft')->toArray();

	expect($object['title'])->toBe('New title, still a draft');
	expect($object['draft'])->toBeTrue();
});

it('leaves a draft alone when an edit sends an empty-string publish value', function (): void {
	// An empty string is not a client asking to publish — it's XML-RPC's usual
	// shape for "no value here" (an empty <string></string>). Before the fix,
	// publishFlag()'s "unrecognized value" default of `true` caught this case
	// too, so a client sending "" for the fifth param would silently publish
	// a draft it never asked to change.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'empty-publish-stays-draft',
		'title'   => 'Original title',
		'content' => '<p>Original body</p>',
		'draft'   => true,
	]);

	$key = xmlRpcKey();

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('empty-publish-stays-draft') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'New title'])
		. xmlRpcParam('')
	))->getBody();

	expect($body)->not->toContain('<fault>');

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'empty-publish-stays-draft')->toArray();

	expect($object['title'])->toBe('New title');
	expect($object['draft'])->toBeTrue();
});

it('turns a published post into a draft when an edit explicitly sends publish=false', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'goes-to-draft',
		'title'   => 'Published post',
		'content' => '<p>Body</p>',
		'draft'   => false,
	]);

	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('goes-to-draft') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Pulled back to draft'])
		. xmlRpcBoolParam(false)
	));

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'goes-to-draft')->toArray();

	expect($object['draft'])->toBeTrue();
});

it('publishes a draft when an edit explicitly sends publish=true', function (): void {
	// Proves the intended switch still works: an explicit flag is honoured
	// exactly as before, only an ABSENT flag is now treated differently.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'goes-live',
		'title'   => 'Draft post',
		'content' => '<p>Body</p>',
		'draft'   => true,
	]);

	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('goes-live') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Now published'])
		. xmlRpcBoolParam(true)
	));

	$object = $container->get(ObjectFetcher::class)->fetchObject('blog', 'goes-live')->toArray();

	expect($object['draft'])->toBeFalse();
});

it('changes nothing at all when an edit sends an empty struct and no publish flag', function (): void {
	// This is what makes the `$fields === []` short-circuit in applyEdit()
	// live again: with `draft` no longer set unconditionally, an empty struct
	// with no publish flag maps to a genuinely empty field set.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'untouched',
		'title'   => 'Leave me alone',
		'content' => '<p>Do not change</p>',
		'summary' => 'Untouched summary',
		'draft'   => true,
	]);

	$before = $container->get(ObjectFetcher::class)->fetchObject('blog', 'untouched')->toArray();

	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('untouched') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam([])
	));

	$after = $container->get(ObjectFetcher::class)->fetchObject('blog', 'untouched')->toArray();

	expect($after)->toBe($before);
});

it('never renames a post when a client sends a different wp_slug', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'keep-this-id', 'title' => 'Keep this id']);

	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('keep-this-id') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Renamed', 'wp_slug' => 'brand-new-id'])
		. xmlRpcBoolParam(true)
	));

	$fetcher = $container->get(ObjectFetcher::class);
	expect($fetcher->existsObject('blog', 'keep-this-id'))->toBeTrue();
	expect($fetcher->existsObject('blog', 'brand-new-id'))->toBeFalse();
	expect($fetcher->fetchObject('blog', 'keep-this-id')->toArray()['title'])->toBe('Renamed');
});

it('generates a unique id when two posts share a title', function (): void {
	$key = xmlRpcKey();

	foreach (['first', 'second'] as $pass) {
		postXmlRpc(xmlRpcBody(
			'metaWeblog.newPost',
			xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
			. xmlRpcStructParam(['title' => 'Duplicate title'])
			. xmlRpcBoolParam(true)
		));
	}

	$fetcher = $this->app->getContainer()->get(ObjectFetcher::class);
	expect($fetcher->existsObject('blog', 'duplicate-title'))->toBeTrue();
	expect($fetcher->existsObject('blog', 'duplicate-title-2'))->toBeTrue();
});

it('deletes a post through both dialects', function (): void {
	$container = $this->app->getContainer();
	$saver     = $container->get(ObjectSaver::class);
	$saver->saveObject('blog', ['id' => 'delete-blogger', 'title' => 'Delete via blogger']);
	$saver->saveObject('blog', ['id' => 'delete-wp', 'title' => 'Delete via wp']);

	$key     = xmlRpcKey();
	$fetcher = $container->get(ObjectFetcher::class);

	// blogger.deletePost(appkey, postid, username, password, publish)
	postXmlRpc(xmlRpcBody(
		'blogger.deletePost',
		xmlRpcParam('0000') . xmlRpcParam('delete-blogger') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcBoolParam(true)
	));
	expect($fetcher->existsObject('blog', 'delete-blogger'))->toBeFalse();

	// wp.deletePost(blogid, username, password, postid) — different order.
	postXmlRpc(xmlRpcBody(
		'wp.deletePost',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key) . xmlRpcParam('delete-wp')
	));
	expect($fetcher->existsObject('blog', 'delete-wp'))->toBeFalse();
});

it('refuses to delete with a key that cannot DELETE', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'survives', 'title' => 'Survives']);

	$key = xmlRpcKey(['blog'], ['GET', 'POST', 'PUT']);

	// The key authenticates fine (it is granted the path) and is refused at
	// assertOperation()'s RPC-level check — the operation gate, not the
	// credential gate.
	$body = (string)postXmlRpc(xmlRpcBody(
		'blogger.deletePost',
		xmlRpcParam('0000') . xmlRpcParam('survives') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->toContain('<int>401</int>');
	expect($container->get(ObjectFetcher::class)->existsObject('blog', 'survives'))->toBeTrue();
});

it('publishes into the URL-pinned collection while ignoring blogid', function (): void {
	// Immunity to clients that hardcode blogid=1: the URL wins outright.
	$key = xmlRpcKey();

	postXmlRpc(xmlRpcBody(
		'metaWeblog.newPost',
		xmlRpcParam('1') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Pinned by URL', 'wp_slug' => 'pinned-by-url'])
		. xmlRpcBoolParam(true)
	), '/xmlrpc/blog');

	expect($this->app->getContainer()->get(ObjectFetcher::class)->existsObject('blog', 'pinned-by-url'))
		->toBeTrue();
});
