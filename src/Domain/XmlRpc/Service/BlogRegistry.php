<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Service;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
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
		private ObjectFetcher $objectFetcher,
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
	 * Resolve which blog collection a call targets, for methods that carry a
	 * `blogid` parameter.
	 *
	 * URL-pinned collection wins outright — `blogid` is ignored entirely on
	 * that route, which is what makes the endpoint immune to clients that
	 * hardcode `blogid=1`. Otherwise `blogid` is used if the client sent one.
	 * Failing both — no URL pin and no blogid — this falls back to
	 * `reset($blogs)`, the alphabetically first collection the key can see.
	 * That fallback is only correct when the key can see exactly one blog; at
	 * any higher blog count it is a guess. It applies here because every
	 * caller of this method carries a genuine `blogid` in the WordPress
	 * protocol, so the guess only fires when the client's own blogid was
	 * empty — a caller misbehaving, not this method's normal path. Methods
	 * whose protocol carries no blogid at all (`metaWeblog.getPost`,
	 * `metaWeblog.editPost`, `mt.getPostCategories`, `mt.setPostCategories`,
	 * `blogger.deletePost`) must NOT use this method — see
	 * `resolveForPost()`. A key with no blogs at all faults.
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
	 * Resolve which blog collection a call targets, for the five methods
	 * whose WordPress protocol carries no `blogid` at all: only a `postid`.
	 *
	 * A URL-pinned collection still wins outright, same as `resolveFor()`.
	 * Otherwise, guessing (`reset($blogs)`) is exactly the bug this method
	 * exists to remove: with more than one visible blog, the wrong post could
	 * be read, edited, or deleted. Instead this locates the post by searching
	 * every collection the key can see. Exactly one match resolves cleanly.
	 * No match faults 404 naming the post. More than one match faults rather
	 * than picking — silently touching the wrong copy of a shared id is worse
	 * than a client's editor reporting an error — and points the client at the
	 * `/xmlrpc/{collection}` endpoint form to disambiguate.
	 */
	public function resolveForPost(XmlRpcIdentity $identity, ?string $urlCollection, string $postId): CollectionData
	{
		if ($urlCollection !== null) {
			return $this->assertBlog($identity, $urlCollection);
		}

		$blogs = $this->blogsFor($identity);
		if ($blogs === []) {
			throw XmlRpcFault::notFound('This API key has access to no blog collections.');
		}

		$matches = [];
		foreach ($blogs as $blog) {
			if ($this->objectFetcher->existsObject($blog->id, $postId)) {
				$matches[] = $blog;
			}
		}

		if (count($matches) === 1) {
			return $matches[0];
		}

		if ($matches === []) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		throw XmlRpcFault::forbidden(sprintf(
			'Post id "%s" is ambiguous: it exists in more than one blog collection this API key '
				. 'can see (%s). Use the /xmlrpc/{collection} endpoint form to target one directly.',
			$postId,
			implode(', ', array_map(static fn (CollectionData $blog): string => $blog->id, $matches))
		));
	}

	/**
	 * Whether the key's `paths` scope grants this specific collection.
	 *
	 * Delegates entirely to the shared checker, which now matches on path-
	 * segment boundaries itself, so a key scoped to `/collections/blog` no
	 * longer also matches `/collections/blog-archive`.
	 */
	private function grantsCollection(ApiKeyData $apiKey, string $collectionId): bool
	{
		return $this->permissions->allowsPath($apiKey, '/collections/' . $collectionId);
	}
}
