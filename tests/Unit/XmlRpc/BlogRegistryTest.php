<?php

declare(strict_types=1);

use Tests\Unit\XmlRpc\Stubs\BlogRegistryStubCollectionLister;
use Tests\Unit\XmlRpc\Stubs\BlogRegistryStubObjectFetcher;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
use TotalCMS\Domain\XmlRpc\Service\BlogRegistry;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

require_once __DIR__ . '/XmlRpcUnitHelpers.php';

function xmlRpcIdentity(array $scopes): XmlRpcIdentity
{
	return new XmlRpcIdentity(
		new ApiKeyData([
			'id'      => 'key-1',
			'name'    => 'MarsEdit on the laptop',
			'key'     => 'tcms_testkey',
			'created' => '2026-07-28T00:00:00Z',
			'scopes'  => $scopes,
		]),
		'Joe Workman',
	);
}

/**
 * The lister and object-fetcher doubles live in Stubs/ as autoloaded classes
 * (readonly parents force readonly subclasses, and the anonymous readonly
 * form is PHP 8.3+ while CI runs the 8.2 floor). `ApiKeyPermissionChecker`
 * has no dependencies, so the real service is used directly rather than
 * doubled.
 *
 * @param array<CollectionData> $collections
 */
function makeBlogRegistry(array $collections): BlogRegistry
{
	return new BlogRegistry(
		new BlogRegistryStubCollectionLister($collections),
		new ApiKeyPermissionChecker(),
		new BlogRegistryStubObjectFetcher(),
	);
}

it('returns only blog collections the key scopes permit', function (): void {
	$registry = makeBlogRegistry([
		blogCollection('blog'),
		blogCollection('news'),
	]);

	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/xmlrpc.php', '/collections/blog']]);

	expect(array_keys($registry->blogsFor($identity)))->toBe(['blog']);
});

it('faults with 404 for a collection outside the key scopes', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('news')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/xmlrpc.php', '/collections/blog']]);

	expect(fn (): CollectionData => $registry->assertBlog($identity, 'news'))
		->toThrow(XmlRpcFault::class);
});

it('includes blog-legacy collections', function (): void {
	$registry = makeBlogRegistry([blogCollection('old', 'blog-legacy')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['*']]);

	expect(array_keys($registry->blogsFor($identity)))->toBe(['old']);
});

/*
 * ApiKeyPermissionChecker::allowsPath() matches with str_starts_with(), so a
 * key scoped to "/collections/blog" would also match "/collections/blog-
 * archive" under that logic alone. BlogRegistry must not let that stand: it
 * builds authorization directly on this scoping, so a naive prefix match
 * would silently grant publish access to sibling collections that merely
 * share a name prefix. These four cases pin the segment-boundary fix.
 */
it('does not let a collection-scoped key see a sibling collection sharing its name prefix', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('blog-archive')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/collections/blog']]);

	expect(array_keys($registry->blogsFor($identity)))->toBe(['blog']);
});

it('still sees the exact collection a collection-scoped key names', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('blog-archive')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/collections/blog']]);

	expect($registry->blogsFor($identity))->toHaveKey('blog');
});

it('sees every collection when scoped to the /collections umbrella', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('blog-archive')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/collections']]);

	// blogsFor() ksort()s its result, so alphabetical order is deterministic.
	expect(array_keys($registry->blogsFor($identity)))->toBe(['blog', 'blog-archive']);
});

it('sees every collection when scoped to the wildcard', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('blog-archive')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['*']]);

	// blogsFor() ksort()s its result, so alphabetical order is deterministic.
	expect(array_keys($registry->blogsFor($identity)))->toBe(['blog', 'blog-archive']);
});

/*
 * resolveFor() is now the single resolver behind every XML-RPC handler, so its
 * branches are pinned directly rather than relying on handler-level tests —
 * every prior fixture had exactly one visible blog, which never exercised the
 * "choose among several" or "no visible blogs" paths.
 */
it('resolves the blog named by blogid when the key can see more than one', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('news')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/collections']]);

	expect($registry->resolveFor($identity, null, 'news')->id)->toBe('news');
});

it('ignores blogid when the route is URL-pinned, even to a different visible blog', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('news')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/collections']]);

	// URL names "blog", blogid names "news" — the URL must win outright.
	expect($registry->resolveFor($identity, 'blog', 'news')->id)->toBe('blog');
});

it('faults resolveFor when the key is scoped to no collection at all', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('news')]);
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/xmlrpc.php']]);

	expect(fn (): CollectionData => $registry->resolveFor($identity, null))
		->toThrow(XmlRpcFault::class);
});

it('faults resolveFor on a blogid outside the key scope rather than falling back to a visible blog', function (): void {
	$registry = makeBlogRegistry([blogCollection('blog'), blogCollection('news')]);
	// Scoped to "blog" only, but the call asks for "news" by blogid — this must
	// fault, not silently resolve to "blog" (the one blog it *can* see). A
	// fallback here would let a blogid typo or a compromised client publish
	// into a collection the key was never granted.
	$identity = xmlRpcIdentity(['methods' => ['GET'], 'paths' => ['/xmlrpc.php', '/collections/blog']]);

	expect(fn (): CollectionData => $registry->resolveFor($identity, null, 'news'))
		->toThrow(XmlRpcFault::class);
});
