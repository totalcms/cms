<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	enableXmlRpc();
	xmlRpcTestApp()->getContainer()
		->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)
		->fetchOrCreateReserved('blog');
});

it('lists supported methods without credentials', function (): void {
	$response = postXmlRpc(xmlRpcBody('mt.supportedMethods'));
	$body     = (string)$response->getBody();

	expect($body)->toContain('blogger.getUsersBlogs');
	expect($body)->toContain('metaWeblog.newPost');
	// The two features behind WordPress's XML-RPC reputation must never appear.
	expect($body)->not->toContain('system.multicall');
	expect($body)->not->toContain('pingback');
});

it('reports no text filters', function (): void {
	expect((string)postXmlRpc(xmlRpcBody('mt.supportedTextFilters'))->getBody())
		->toContain('<array><data></data></array>');
});

it('faults on getUsersBlogs without a valid key', function (): void {
	$response = postXmlRpc(xmlRpcBody('blogger.getUsersBlogs',
		xmlRpcParam('0000') . xmlRpcParam('joe') . xmlRpcParam('tcms_not_real')));

	$body = (string)$response->getBody();
	expect($body)->toContain('<fault>');
	// 403 bad credentials, or 401 when the test env is not Pro. Never a blog list.
	expect($body)->toMatch('/<int>(403|401)<\/int>/');
	expect($body)->not->toContain('blogName');
});

it('lists the blogs a key is scoped to, and only those', function (): void {
	$container = xmlRpcTestApp()->getContainer();
	$container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');

	// A second blog collection the key is NOT scoped to must not appear.
	$container->get(TotalCMS\Domain\Collection\Service\CollectionSaver::class)
		->saveCollection(['id' => 'news', 'name' => 'News', 'schema' => 'blog']);

	$key  = xmlRpcKey(['blog']);
	$body = (string)postXmlRpc(xmlRpcBody('blogger.getUsersBlogs',
		xmlRpcParam('0000') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>blogid</name><value><string>blog</string></value>');
	expect($body)->not->toContain('<string>news</string>');
});

it('handles wp.getUsersBlogs shifted param positions', function (): void {
	// wp.* puts username at 0 and password at 1; blogger.* shifts both by one for
	// the legacy appkey. Getting this wrong reads to the user as an auth failure.
	$key  = xmlRpcKey(['blog']);
	$body = (string)postXmlRpc(xmlRpcBody('wp.getUsersBlogs',
		xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>blogName</name>');
});

it('advertises Total CMS and no thumbnail support in wp.getOptions', function (): void {
	$key  = xmlRpcKey(['blog']);
	$body = (string)postXmlRpc(xmlRpcBody('wp.getOptions',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->toContain('<value><string>Total CMS</string></value>');
	// post_thumbnail: false is how a client learns not to offer featured-image UI.
	expect($body)->toContain('<name>post_thumbnail</name>');
	expect($body)->toMatch('/<name>post_thumbnail<\/name>.*?<boolean>0<\/boolean>/s');
});

it('refuses a key that lacks the collection grant', function (): void {
	// Endpoint grant without a collection grant authenticates, then reports no
	// blogs — the confusing failure the key UI is meant to prevent.
	$key = xmlRpcTestApp()->getContainer()
		->get(TotalCMS\Domain\ApiKey\Service\ApiKeyCreator::class)
		->createApiKey('endpoint only', ['methods' => ['GET'], 'paths' => ['/xmlrpc.php']])
		->key;

	$body = (string)postXmlRpc(xmlRpcBody('blogger.getUsersBlogs',
		xmlRpcParam('0000') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<name>blogid</name>');
});
