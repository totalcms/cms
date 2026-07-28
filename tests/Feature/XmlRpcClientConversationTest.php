<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

use TotalCMS\Domain\XmlRpc\Service\MethodRouter;

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

it('faults on media upload with a message pointing at the admin', function (): void {
	$body = (string)postXmlRpc(xmlRpcBody('metaWeblog.newMediaObject',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam(xmlRpcKey())))->getBody();

	expect($body)->toContain('<fault>');
	// The fault string is what the user actually reads in MarsEdit's dialog, so
	// it must name where to add images — not "method does not exist".
	expect($body)->toContain('Total CMS admin');
	expect($body)->not->toContain('-32601');
});

it('faults on page methods with an explanation', function (): void {
	foreach (['wp.getPages', 'wp.newPage', 'wp.getPageList'] as $method) {
		$body = (string)postXmlRpc(xmlRpcBody($method,
			xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam(xmlRpcKey())))->getBody();

		expect($body)->toContain('does not expose pages');
		expect($body)->not->toContain('-32601');
	}
});

it('runs the full client conversation end to end', function (): void {
	// The sequence MarsEdit actually performs: enumerate, list, publish, read
	// back, edit, delete. If this passes, a real client works.
	$key      = xmlRpcKey();
	$fetcher  = $this->app->getContainer()->get(TotalCMS\Domain\Object\Service\ObjectFetcher::class);
	$creds    = xmlRpcParam('joe') . xmlRpcParam($key);

	$blogs = (string)postXmlRpc(xmlRpcBody('blogger.getUsersBlogs', xmlRpcParam('0000') . $creds))->getBody();
	expect($blogs)->toContain('<string>blog</string>');

	$published = (string)postXmlRpc(xmlRpcBody('metaWeblog.newPost',
		xmlRpcParam('blog') . $creds
		. xmlRpcStructParam([
			'title'       => 'Round trip',
			'description' => '<p>Written in a client.</p>',
			'categories'  => ['Tech'],
			'wp_slug'     => 'round-trip',
		])
		. xmlRpcBoolParam(true)))->getBody();
	expect($published)->toContain('round-trip');

	$read = (string)postXmlRpc(xmlRpcBody('metaWeblog.getPost',
		xmlRpcParam('round-trip') . $creds))->getBody();
	expect($read)->toContain('Written in a client.');

	postXmlRpc(xmlRpcBody('metaWeblog.editPost',
		xmlRpcParam('round-trip') . $creds
		. xmlRpcStructParam(['title' => 'Round trip, edited'])
		. xmlRpcBoolParam(true)));
	expect($fetcher->fetchObject('blog', 'round-trip')->toArray()['title'])->toBe('Round trip, edited');
	// The edit sent no description, so the body must be untouched.
	expect($fetcher->fetchObject('blog', 'round-trip')->toArray()['content'])->toContain('Written in a client.');

	postXmlRpc(xmlRpcBody('blogger.deletePost',
		xmlRpcParam('0000') . xmlRpcParam('round-trip') . $creds . xmlRpcBoolParam(true)));
	expect($fetcher->existsObject('blog', 'round-trip'))->toBeFalse();
});

it('never advertises multicall or pingback', function (): void {
	$names = $this->app->getContainer()->get(MethodRouter::class)->methodNames();

	expect($names)->not->toContain('system.multicall');
	foreach ($names as $name) {
		expect($name)->not->toStartWith('pingback.');
	}
});

it('parses a captured MarsEdit newPost payload without faulting on transport', function (): void {
	$xml  = (string)file_get_contents(__DIR__ . '/../fixtures/xmlrpc/marsedit-newpost.xml');
	$body = (string)postXmlRpc($xml)->getBody();

	// Credentials in the fixture are not real, so a 403/401 fault is correct.
	// What matters is that it is an AUTH fault, not a transport fault: the
	// payload shape parsed cleanly.
	expect($body)->toContain('<fault>');
	expect($body)->not->toContain('-32700');
	expect($body)->not->toContain('-32601');
});
