<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Service;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

/**
 * Which collections a caller may publish into: blog-schema collections
 * intersected with the key's `paths` scopes.
 *
 * Reusing the key's collection paths means per-collection authorization needs
 * no new configuration — a key scoped to /collections/blog sees exactly that
 * one, and `getUsersBlogs` reports the same set.
 */
readonly class BlogRegistry
{
	/** Schemas whose collections are publishable. */
	public const BLOG_SCHEMAS = ['blog', 'blog-legacy'];

	public function __construct(
		private CollectionLister $collectionLister,
		private ApiKeyPermissionChecker $permissions,
	) {
	}

	/** @return array<string,CollectionData> */
	public function blogsFor(XmlRpcIdentity $identity): array
	{
		$blogs = [];

		foreach (self::BLOG_SCHEMAS as $schema) {
			foreach ($this->collectionLister->listCollectionsWithSchema($schema) as $collection) {
				if ($this->grantsCollection($identity->apiKey, $collection->id)) {
					$blogs[$collection->id] = $collection;
				}
			}
		}

		ksort($blogs);

		return $blogs;
	}

	public function assertBlog(XmlRpcIdentity $identity, string $collection): CollectionData
	{
		$blogs = $this->blogsFor($identity);

		if ($collection === '' || !isset($blogs[$collection])) {
			throw XmlRpcFault::notFound(sprintf(
				'No blog available at "%s" for this API key. Check the key is scoped to /collections/%s.',
				$collection,
				$collection !== '' ? $collection : '{collection}'
			));
		}

		return $blogs[$collection];
	}

	/**
	 * Resolve which blog collection a call targets, shared by every handler.
	 *
	 * URL-pinned collection wins outright — `blogid` is ignored entirely on
	 * that route, which is what makes the endpoint immune to clients that
	 * hardcode `blogid=1`. Otherwise `blogid` is used if the client sent one.
	 * Failing both, a single-blog site falls back to the only blog the key can
	 * see, so a client that omits `blogid` altogether still works. A key with
	 * no blogs at all faults.
	 */
	public function resolveFor(XmlRpcIdentity $identity, ?string $urlCollection, string $blogId = ''): CollectionData
	{
		if ($urlCollection !== null) {
			return $this->assertBlog($identity, $urlCollection);
		}

		if ($blogId !== '') {
			return $this->assertBlog($identity, $blogId);
		}

		$blogs = $this->blogsFor($identity);
		if ($blogs === []) {
			throw XmlRpcFault::notFound('This API key has access to no blog collections.');
		}

		return reset($blogs);
	}

	/**
	 * Whether the key's `paths` scope grants this specific collection.
	 *
	 * `ApiKeyPermissionChecker::allowsPath()` matches with `str_starts_with()`,
	 * so a key scoped to `/collections/blog` also matches `/collections/
	 * blog-archive` — a real authorization hole for this endpoint, since a key
	 * meant for one blog would silently reach sibling collections that merely
	 * share a name prefix. That looser matching is correct (and load-bearing
	 * elsewhere) for the umbrella grants — `*` and `/collections` itself — so
	 * those are still delegated to the shared checker to stay consistent with
	 * it. Anything more specific is re-verified here with a path-segment
	 * boundary: a granted prefix only counts if the next character in the
	 * target path is `/` (a true parent segment) or nothing (an exact match).
	 *
	 * This is deliberately NOT a fix to `ApiKeyPermissionChecker` itself — that
	 * checker is shared with the REST API, which has its own review underway;
	 * this tightens only the boundary this class owns.
	 */
	private function grantsCollection(ApiKeyData $apiKey, string $collectionId): bool
	{
		if ($this->permissions->allowsPath($apiKey, '/collections')) {
			return true;
		}

		$target = strtolower('collections/' . $collectionId);

		/** @var array<int,mixed> $paths */
		$paths = is_array($apiKey->scopes['paths'] ?? null) ? $apiKey->scopes['paths'] : [];

		foreach ($paths as $path) {
			$path = strtolower(ltrim((string)$path, '/'));

			if ($path === $target || str_starts_with($target, $path . '/')) {
				return true;
			}
		}

		return false;
	}
}
