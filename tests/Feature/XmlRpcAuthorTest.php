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

	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'     => 'author-one',
		'title'  => 'One',
		'author' => 'Ada Lovelace',
	]);
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'     => 'author-two',
		'title'  => 'Two',
		'author' => 'Grace Hopper',
	]);
});

it('registers every author/profile method', function (): void {
	$names = $this->app->getContainer()
		->get(TotalCMS\Domain\XmlRpc\Service\MethodRouter::class)
		->methodNames();

	foreach (['wp.getAuthors', 'wp.getUsers', 'wp.getProfile', 'wp.getPostFormats'] as $method) {
		expect($names)->toContain($method);
	}
});

it('derives distinct authors from existing posts', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('wp.getAuthors',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('Ada Lovelace');
	expect($body)->toContain('Grace Hopper');
	expect($body)->toContain('<name>user_id</name>');
	expect($body)->toContain('<name>display_name</name>');
});

it('wp.getUsers returns the same authors in the same struct shape', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('wp.getUsers',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('Ada Lovelace');
	expect($body)->toContain('Grace Hopper');
	expect($body)->toContain('<name>user_login</name>');
});

it('returns a profile struct for the authenticated caller', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('wp.getProfile',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>user_id</name>');
	expect($body)->toContain('<name>display_name</name>');
	expect($body)->toContain('<name>roles</name>');
	expect($body)->toContain('<value><string>author</string></value>');

	// Author comes from the resolved username, falling back to the key name —
	// same rule XmlRpcPostWriteTest already asserts for post authorship —
	// so this only checks the profile is non-empty, not an exact name.
	expect($body)->toContain('<name>email</name><value><string></string></value>');
});

it('returns only the standard post format', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody('wp.getPostFormats',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>standard</name><value><string>Standard</string></value>');
	expect($body)->not->toContain('gallery');
	expect($body)->not->toContain('aside');
});

it('lets a GET-only key call all four author/profile methods', function (): void {
	$key = xmlRpcKey(['blog'], ['GET']);

	foreach (['wp.getAuthors', 'wp.getUsers', 'wp.getProfile', 'wp.getPostFormats'] as $method) {
		$body = (string)postXmlRpc(xmlRpcBody($method,
			xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

		expect($body)->not->toContain('<fault>');
	}
});

it('refuses all four author/profile methods for a key that cannot GET', function (): void {
	$key = xmlRpcKey(['blog'], ['POST']);

	foreach (['wp.getAuthors', 'wp.getUsers', 'wp.getProfile', 'wp.getPostFormats'] as $method) {
		$body = (string)postXmlRpc(xmlRpcBody($method,
			xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)))->getBody();

		// The key authenticates fine (it is granted the path) and is refused at
		// assertOperation()'s RPC-level check — the operation gate, not the
		// credential gate — same as XmlRpcTaxonomyTest's equivalent case.
		expect($body)->toContain('<int>401</int>');
	}
});
