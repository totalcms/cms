<?php

declare(strict_types=1);

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
use TotalCMS\Domain\XmlRpc\Service\BlogRegistry;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

function blogCollection(string $id, string $schema = 'blog'): CollectionData
{
	$collection         = new CollectionData();
	$collection->id     = $id;
	$collection->name   = ucfirst($id);
	$collection->schema = $schema;

	return $collection;
}

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
 * `CollectionLister` is a `readonly class`, so the anonymous subclass below
 * must itself be declared `readonly` (a non-readonly class cannot extend a
 * readonly one) — same rule applied in XmlRpcAuthTest's ApiKeyFetcher/
 * UserValidationService doubles. `ApiKeyPermissionChecker` has no
 * dependencies, so the real service is used directly rather than doubled.
 *
 * @param array<CollectionData> $collections
 */
function makeBlogRegistry(array $collections): BlogRegistry
{
	$lister = new readonly class ($collections) extends CollectionLister {
		/** @param array<CollectionData> $collections */
		public function __construct(private array $collections)
		{
		}

		public function listCollectionsWithSchema(string $schemaId): array
		{
			return array_values(array_filter(
				$this->collections,
				fn (CollectionData $collection): bool => $collection->schema === $schemaId
			));
		}
	};

	return new BlogRegistry($lister, new ApiKeyPermissionChecker());
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
