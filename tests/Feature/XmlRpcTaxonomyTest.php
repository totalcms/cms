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
	$container = $this->app->getContainer();
	$container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');

	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'         => 'taxonomy-one',
		'title'      => 'One',
		'categories' => ['Tech', 'PHP'],
		'tags'       => ['flat-file', 'cms'],
	]);
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'         => 'taxonomy-two',
		'title'      => 'Two',
		'categories' => ['Tech'],
		'tags'       => ['cms'],
	]);
});

it('registers every taxonomy method', function (): void {
	$names = $this->app->getContainer()
		->get(TotalCMS\Domain\XmlRpc\Service\MethodRouter::class)
		->methodNames();

	foreach ([
		'metaWeblog.getCategories', 'mt.getCategoryList', 'wp.getCategories',
		'wp.getTags', 'mt.getPostCategories', 'mt.setPostCategories', 'wp.newCategory',
	] as $method) {
		expect($names)->toContain($method);
	}
});

it('derives distinct categories from existing posts, deduplicated', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getCategories',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('<name>categoryName</name><value><string>Tech</string></value>');
	expect($body)->toContain('<name>categoryName</name><value><string>PHP</string></value>');
	// "Tech" is on both fixture posts but must be reported once: exactly one
	// struct per distinct category name, not one per post it appears on.
	// Counting `categoryId` members (one per returned struct) asserts that
	// dedup guarantee directly, rather than counting how many times the name
	// string happens to appear anywhere in the body.
	expect(substr_count($body, '<name>categoryId</name>'))->toBe(2);
});

it('derives distinct tags from existing posts', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.getTags',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->toContain('flat-file');
	expect($body)->toContain('cms');
	expect($body)->toContain('<name>tag_id</name>');
});

it('reports a post categories with the first marked primary', function (): void {
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'mt.getPostCategories',
		xmlRpcParam('taxonomy-one') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->toContain('<name>isPrimary</name><value><boolean>1</boolean></value>');
	expect($body)->toContain('<name>isPrimary</name><value><boolean>0</boolean></value>');
});

it('replaces post categories without disturbing other fields', function (): void {
	$key = xmlRpcKey();

	$body = (string)postXmlRpc(xmlRpcBody(
		'mt.setPostCategories',
		xmlRpcParam('taxonomy-two') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><array><data>'
		. '<value><struct><member><name>categoryName</name><value><string>Rewritten</string></value></member></struct></value>'
		. '</data></array></value></param>'
	))->getBody();

	expect($body)->not->toContain('<fault>');

	$object = $this->app->getContainer()->get(ObjectFetcher::class)
		->fetchObject('blog', 'taxonomy-two')->toArray();

	expect($object['categories'])->toBe(['Rewritten']);
	// Patch semantics: a taxonomy-only write must leave everything else alone.
	expect($object['title'])->toBe('Two');
	expect($object['tags'])->toBe(['cms']);
});

it('echoes a new category without persisting it', function (): void {
	// There is no taxonomy store — categories are derived from posts — so the
	// name comes back and materializes once a post uses it.
	$key  = xmlRpcKey();
	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.newCategory',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['name' => 'Brand New'])
	))->getBody();

	expect($body)->toContain('<value><string>Brand New</string></value>');

	$listed = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getCategories',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($listed)->not->toContain('Brand New');
});

it('faults on unauthenticated category reads', function (): void {
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getCategories',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam('tcms_not_real')
	))->getBody();

	expect($body)->toContain('<int>403</int>');
	expect($body)->not->toContain('categoryName');
});

it('lets a GET-only key list categories and tags', function (): void {
	$key = xmlRpcKey(['blog'], ['GET']);

	$categories = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getCategories',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();
	$tags = (string)postXmlRpc(xmlRpcBody(
		'wp.getTags',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($categories)->not->toContain('<fault>');
	expect($categories)->toContain('Tech');
	expect($tags)->not->toContain('<fault>');
	expect($tags)->toContain('flat-file');
});

it('refuses mt.setPostCategories with a key that cannot PUT, leaving the post unchanged', function (): void {
	$key = xmlRpcKey(['blog'], ['GET']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'mt.setPostCategories',
		xmlRpcParam('taxonomy-two') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><array><data>'
		. '<value><struct><member><name>categoryName</name><value><string>Rewritten</string></value></member></struct></value>'
		. '</data></array></value></param>'
	))->getBody();

	// The key authenticates fine (it is granted the path) and is refused at
	// assertOperation()'s RPC-level check — the operation gate, not the
	// credential gate.
	expect($body)->toContain('<int>401</int>');

	$object = $this->app->getContainer()->get(ObjectFetcher::class)
		->fetchObject('blog', 'taxonomy-two')->toArray();

	expect($object['categories'])->toBe(['Tech']);
});

it('refuses wp.newCategory with a key that cannot POST', function (): void {
	$key = xmlRpcKey(['blog'], ['GET']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'wp.newCategory',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['name' => 'Should Not Land'])
	))->getBody();

	expect($body)->toContain('<int>401</int>');

	$listed = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getCategories',
		xmlRpcParam('blog') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($listed)->not->toContain('Should Not Land');
});
