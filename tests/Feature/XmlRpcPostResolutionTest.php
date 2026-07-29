<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

/**
 * BlogRegistry::resolveForPost() coverage — the five WordPress methods that
 * carry no `blogid` at all (metaWeblog.getPost, metaWeblog.editPost,
 * mt.getPostCategories, mt.setPostCategories, blogger.deletePost) used to
 * fall back to `reset($blogs)`, the alphabetically first visible collection,
 * whenever more than one blog was granted. With two granted collections that
 * silently read/edited/deleted the WRONG post. These tests pin the fix: the
 * post is located by searching the collections the key can see, not guessed.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	enableXmlRpc();

	$container = $this->app->getContainer();
	$container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$container->get(TotalCMS\Domain\Collection\Service\CollectionSaver::class)
		->saveCollection(['id' => 'news', 'name' => 'News', 'schema' => 'blog']);
});

it('edits a post that exists only in the second granted collection', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('news', [
		'id'      => 'news-only',
		'title'   => 'Original news title',
		'content' => '<p>Original news body</p>',
	]);

	$key = xmlRpcKey(['blog', 'news']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('news-only') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Edited news title'])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->not->toContain('<fault>');

	$fetcher = $container->get(ObjectFetcher::class);
	expect($fetcher->fetchObject('news', 'news-only')->toArray()['title'])->toBe('Edited news title');
	// Must not have been invented in the other visible collection either.
	expect($fetcher->existsObject('blog', 'news-only'))->toBeFalse();
});

it('faults rather than editing either collection when the post id exists in both', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id' => 'shared-id', 'title' => 'Blog copy', 'content' => '<p>Blog body</p>',
	]);
	$container->get(ObjectSaver::class)->saveObject('news', [
		'id' => 'shared-id', 'title' => 'News copy', 'content' => '<p>News body</p>',
	]);

	$key = xmlRpcKey(['blog', 'news']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('shared-id') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Should not land anywhere'])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->toContain('<fault>');

	$fetcher = $container->get(ObjectFetcher::class);
	expect($fetcher->fetchObject('blog', 'shared-id')->toArray()['title'])->toBe('Blog copy');
	expect($fetcher->fetchObject('news', 'shared-id')->toArray()['title'])->toBe('News copy');
});

it('faults 404 when the post exists in neither granted collection', function (): void {
	$key  = xmlRpcKey(['blog', 'news']);
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('nowhere-to-be-found') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'x'])
	))->getBody();

	expect($body)->toContain('<int>404</int>');
});

it('still resolves the single visible blog unchanged when only one collection is granted', function (): void {
	// A second blog collection ("news") exists in the system, but this key is
	// scoped only to "blog" — resolveForPost() must still find the post there
	// without needing the client to say which blog it meant.
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id' => 'single-blog-post', 'title' => 'Original', 'content' => '<p>Body</p>',
	]);

	$key = xmlRpcKey(['blog']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.editPost',
		xmlRpcParam('single-blog-post') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcStructParam(['title' => 'Edited'])
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($container->get(ObjectFetcher::class)->fetchObject('blog', 'single-blog-post')->toArray()['title'])
		->toBe('Edited');
});

it('reads a post that exists only in the second granted collection via metaWeblog.getPost', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('news', [
		'id' => 'news-read-only', 'title' => 'News-only post', 'content' => '<p>Body</p>',
	]);

	$key  = xmlRpcKey(['blog', 'news']);
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getPost',
		xmlRpcParam('news-read-only') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($body)->toContain('News-only post');
});

it('faults metaWeblog.getPost when the id is ambiguous across granted collections', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'dupe', 'title' => 'Blog dupe']);
	$container->get(ObjectSaver::class)->saveObject('news', ['id' => 'dupe', 'title' => 'News dupe']);

	$key  = xmlRpcKey(['blog', 'news']);
	$body = (string)postXmlRpc(xmlRpcBody(
		'metaWeblog.getPost',
		xmlRpcParam('dupe') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->toContain('<fault>');
	expect($body)->not->toContain('<name>postid</name>');
});

it('deletes via blogger.deletePost only the collection that actually holds the post', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('news', ['id' => 'news-delete-only', 'title' => 'Doomed']);

	$key = xmlRpcKey(['blog', 'news']);

	postXmlRpc(xmlRpcBody(
		'blogger.deletePost',
		xmlRpcParam('0000') . xmlRpcParam('news-delete-only') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcBoolParam(true)
	));

	expect($container->get(ObjectFetcher::class)->existsObject('news', 'news-delete-only'))->toBeFalse();
});

it('refuses blogger.deletePost rather than deleting either copy when the id exists in both collections', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'dupe-delete', 'title' => 'Blog copy']);
	$container->get(ObjectSaver::class)->saveObject('news', ['id' => 'dupe-delete', 'title' => 'News copy']);

	$key = xmlRpcKey(['blog', 'news']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'blogger.deletePost',
		xmlRpcParam('0000') . xmlRpcParam('dupe-delete') . xmlRpcParam('joe') . xmlRpcParam($key)
		. xmlRpcBoolParam(true)
	))->getBody();

	expect($body)->toContain('<fault>');

	$fetcher = $container->get(ObjectFetcher::class);
	expect($fetcher->existsObject('blog', 'dupe-delete'))->toBeTrue();
	expect($fetcher->existsObject('news', 'dupe-delete'))->toBeTrue();
});

it('sets categories via mt.setPostCategories only on the collection that actually holds the post', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('news', [
		'id' => 'news-categories', 'title' => 'News post', 'categories' => ['Old'],
	]);

	$key = xmlRpcKey(['blog', 'news']);

	$body = (string)postXmlRpc(xmlRpcBody(
		'mt.setPostCategories',
		xmlRpcParam('news-categories') . xmlRpcParam('joe') . xmlRpcParam($key)
		. '<param><value><array><data>'
		. '<value><struct><member><name>categoryName</name><value><string>New</string></value></member></struct></value>'
		. '</data></array></value></param>'
	))->getBody();

	expect($body)->not->toContain('<fault>');
	expect($container->get(ObjectFetcher::class)->fetchObject('news', 'news-categories')->toArray()['categories'])
		->toBe(['New']);
});

it('faults mt.getPostCategories when the id is ambiguous across granted collections', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'dupe-cat', 'title' => 'Blog', 'categories' => ['A']]);
	$container->get(ObjectSaver::class)->saveObject('news', ['id' => 'dupe-cat', 'title' => 'News', 'categories' => ['B']]);

	$key  = xmlRpcKey(['blog', 'news']);
	$body = (string)postXmlRpc(xmlRpcBody(
		'mt.getPostCategories',
		xmlRpcParam('dupe-cat') . xmlRpcParam('joe') . xmlRpcParam($key)
	))->getBody();

	expect($body)->toContain('<fault>');
});
