<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	enableXmlRpc();
	$container = $this->app->getContainer();
	$container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');

	// Ids are deliberately NOT in date order: alphabetically 'read-post-a' <
	// 'read-post-m' < 'read-post-z', but by date the order is a (newest),
	// z (middle), m (oldest). Neither ascending nor descending id order
	// matches that — so a test asserting date order can't pass by accident
	// if the handler regresses to sorting by id.
	$saver = $container->get(ObjectSaver::class);
	foreach (
		[
			'a' => '2026-07-27T09:00:00Z', // newest
			'z' => '2026-07-20T09:00:00Z', // middle
			'm' => '2026-07-10T09:00:00Z', // oldest
		] as $suffix => $date
	) {
		$saver->saveObject('blog', [
			'id'         => 'read-post-' . $suffix,
			'title'      => 'Read post ' . strtoupper($suffix),
			'content'    => '<p>Body ' . strtoupper($suffix) . '</p>',
			'summary'    => 'Summary ' . strtoupper($suffix),
			'extra'      => '<p>Extended ' . strtoupper($suffix) . '</p>',
			'categories' => ['Tech'],
			'tags'       => ['php'],
			'author'     => 'Joe Workman',
			'draft'      => false,
			'date'       => $date,
		]);
	}
});

it('returns a full post struct for getPost', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getPost',
		xmlRpcParam('read-post-a') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>postid</name><value><string>read-post-a</string></value>');
	expect($body)->toContain('Read post A');
	// The client needs body text to edit — proving reads load the full object and
	// not just the index, which omits `content`.
	expect($body)->toContain('Body A');
	expect($body)->toContain('<name>mt_excerpt</name><value><string>Summary A</string></value>');
	expect($body)->toContain('<name>post_status</name><value><string>publish</string></value>');
});

it('faults with 404 for a post that does not exist', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getPost',
		xmlRpcParam('no-such-post') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->toContain('<int>404</int>');
	expect($body)->not->toContain('<name>postid</name>');
});

it('returns recent posts newest first and honours the requested count', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getRecentPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><int>2</int></value></param>'
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect(substr_count($body, '<name>postid</name>'))->toBe(2);
	// The two most recent by DATE are 'a' (newest) then 'z' (middle) — 'm' (oldest)
	// must be excluded. Sorting by id (asc: a,m / desc: z,m) would either include
	// 'm' or put the pair in the wrong order, so this fails under an id-sort
	// regression rather than passing by coincidence.
	expect($body)->not->toContain('read-post-m');
	expect(strpos($body, 'read-post-a'))->toBeLessThan((int)strpos($body, 'read-post-z'));
});

it('clamps a request for every post to the maximum', function (): void {
	// A client sending -1 ("all") must not be able to pull a whole collection.
	expect(TotalCMS\Domain\XmlRpc\Handler\PostReadHandler::MAX_POSTS)->toBe(100);

	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getRecentPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><int>-1</int></value></param>'
	))->getBody();

	expect($body)->not->toContain('<fault>');
	// Only three posts exist, so the clamp shows up as "all of them, no error".
	expect(substr_count($body, '<name>postid</name>'))->toBe(3);
	// Full date-descending order: a (newest), z (middle), m (oldest).
	expect(strpos($body, 'read-post-a'))->toBeLessThan((int)strpos($body, 'read-post-z'));
	expect(strpos($body, 'read-post-z'))->toBeLessThan((int)strpos($body, 'read-post-m'));
});

it('returns titles only from mt.getRecentPostTitles', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'mt.getRecentPostTitles',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><int>3</int></value></param>'
	))->getBody();

	expect($body)->toContain('<name>title</name>');
	// Index-only method: no body text should be serialized.
	expect($body)->not->toContain('Body A');
	// Same disagreement between id order and date order as getRecentPosts,
	// so this also fails under an id-sort regression rather than passing
	// coincidentally.
	expect(strpos($body, 'read-post-a'))->toBeLessThan((int)strpos($body, 'read-post-z'));
	expect(strpos($body, 'read-post-z'))->toBeLessThan((int)strpos($body, 'read-post-m'));
});

it('lets a GET-only key read a post', function (): void {
	// The capability model's whole point: a key scoped to GET only must still
	// be able to authenticate and read, because XmlRpcAuth::authenticate() now
	// checks only the path grant — the transport being HTTP POST is a detail
	// of XML-RPC, not the caller's requested capability. Before the fix, a
	// GET-only key failed authentication outright (it lacked the hardcoded
	// 'POST' scope check) and could not even read.
	$key  = xmlRpcKey(['blog'], ['GET']);
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getPost',
		xmlRpcParam('read-post-a') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>postid</name><value><string>read-post-a</string></value>');
	expect($body)->toContain('Read post A');
});

it('refuses reads from a key without the collection grant', function (): void {
	$key = xmlRpcKey(['news']);   // scoped to a collection that does not exist here

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getPost',
		xmlRpcParam('read-post-a') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->toContain('<fault>');
	expect($body)->not->toContain('Read post A');
});
