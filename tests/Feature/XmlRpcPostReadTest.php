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

	$saver = $container->get(ObjectSaver::class);
	foreach ([1, 2, 3] as $index) {
		$saver->saveObject('blog', [
			'id'         => 'read-post-' . $index,
			'title'      => 'Read post ' . $index,
			'content'    => '<p>Body ' . $index . '</p>',
			'summary'    => 'Summary ' . $index,
			'extra'      => '<p>Extended ' . $index . '</p>',
			'categories' => ['Tech'],
			'tags'       => ['php'],
			'author'     => 'Joe Workman',
			'draft'      => false,
			'date'       => '2026-07-2' . $index . 'T09:00:00Z',
		]);
	}
});

it('returns a full post struct for getPost', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('metaWeblog.getPost',
		xmlRpcParam('read-post-1') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>postid</name><value><string>read-post-1</string></value>');
	expect($body)->toContain('Read post 1');
	// The client needs body text to edit — proving reads load the full object and
	// not just the index, which omits `content`.
	expect($body)->toContain('Body 1');
	expect($body)->toContain('<name>mt_excerpt</name><value><string>Summary 1</string></value>');
	expect($body)->toContain('<name>post_status</name><value><string>publish</string></value>');
});

it('faults with 404 for a post that does not exist', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('metaWeblog.getPost',
		xmlRpcParam('no-such-post') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->toContain('<int>404</int>');
	expect($body)->not->toContain('<name>postid</name>');
});

it('returns recent posts newest first and honours the requested count', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('metaWeblog.getRecentPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><int>2</int></value></param>'))->getBody();

	expect($body)->not->toContain('<fault>');
	expect(substr_count($body, '<name>postid</name>'))->toBe(2);
	// Newest first: post 3 has the latest date, so it must precede post 2.
	expect(strpos($body, 'read-post-3'))->toBeLessThan((int)strpos($body, 'read-post-2'));
});

it('clamps a request for every post to the maximum', function (): void {
	// A client sending -1 ("all") must not be able to pull a whole collection.
	expect(TotalCMS\Domain\XmlRpc\Handler\PostReadHandler::MAX_POSTS)->toBe(100);

	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('metaWeblog.getRecentPosts',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><int>-1</int></value></param>'))->getBody();

	expect($body)->not->toContain('<fault>');
	// Only three posts exist, so the clamp shows up as "all of them, no error".
	expect(substr_count($body, '<name>postid</name>'))->toBe(3);
});

it('returns titles only from mt.getRecentPostTitles', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('mt.getRecentPostTitles',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><int>3</int></value></param>'))->getBody();

	expect($body)->toContain('<name>title</name>');
	// Index-only method: no body text should be serialized.
	expect($body)->not->toContain('Body 1');
});

it('refuses reads from a key without the collection grant', function (): void {
	$key = xmlRpcKey(['news']);   // scoped to a collection that does not exist here

	$body = (string)postXmlRpc(xmlRpcBody('metaWeblog.getPost',
		xmlRpcParam('read-post-1') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->toContain('<fault>');
	expect($body)->not->toContain('Read post 1');
});
