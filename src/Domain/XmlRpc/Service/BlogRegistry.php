<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Service;

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
				if ($this->permissions->allowsPath($identity->apiKey, '/collections/' . $collection->id)) {
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
}
