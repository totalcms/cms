<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
use TotalCMS\Domain\XmlRpc\Service\BlogRegistry;
use TotalCMS\Domain\XmlRpc\Service\PostMapper;
use TotalCMS\Domain\XmlRpc\Service\XmlRpcAuth;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

/**
 * Create, edit and delete posts.
 *
 * Every write goes through the normal domain services, so events, HTML
 * sanitization (StringData runs HTMLSanitizer on styledtext) and index updates
 * all happen exactly as they do for an admin save.
 */
readonly class PostWriteHandler implements MethodHandler
{
	public function __construct(
		private XmlRpcAuth $auth,
		private BlogRegistry $registry,
		private PostMapper $mapper,
		private ObjectSaver $objectSaver,
		private ObjectPatcher $objectPatcher,
		private ObjectRemover $objectRemover,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/** @return array<string,callable(array<int,mixed>,?string):mixed> */
	public function methods(): array
	{
		return [
			'metaWeblog.newPost'  => $this->newPost(...),
			'metaWeblog.editPost' => $this->editPost(...),
			'blogger.deletePost'  => $this->bloggerDeletePost(...),
			'wp.deletePost'       => $this->wpDeletePost(...),
		];
	}

	/**
	 * metaWeblog.newPost(blogid, username, password, struct, publish)
	 *
	 * @param array<int,mixed> $params
	 */
	public function newPost(array $params, ?string $collection): string
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'POST');

		$blog   = $this->resolveBlog($identity, $collection, (string)($params[0] ?? ''));
		$struct = is_array($params[3] ?? null) ? $params[3] : [];
		$fields = $this->mapper->toObject($struct, $this->publishFlag($params[4] ?? true), true);

		$id = is_string($fields['id'] ?? null) && $fields['id'] !== ''
			? $fields['id']
			: $this->mapper->titleSlug((string)($fields['title'] ?? ''));

		$fields['id']     = $this->uniqueId($blog->id, $id);
		$fields['author'] = $fields['author'] ?? $identity->authorName;

		return $this->objectSaver->saveObject($blog->id, $fields)->id;
	}

	/**
	 * metaWeblog.editPost(postid, username, password, struct, publish)
	 *
	 * @param array<int,mixed> $params
	 */
	public function editPost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'PUT');

		$postId = (string)($params[0] ?? '');
		$blog   = $this->resolveBlog($identity, $collection);

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		$struct = is_array($params[3] ?? null) ? $params[3] : [];
		$fields = $this->mapper->toObject($struct, $this->publishFlag($params[4] ?? true), false);

		$this->applyEdit($blog->id, $postId, $fields);

		return true;
	}

	/**
	 * blogger.deletePost(appkey, postid, username, password, publish)
	 *
	 * @param array<int,mixed> $params
	 */
	public function bloggerDeletePost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 2, 3);

		return $this->delete($identity, $collection, (string)($params[1] ?? ''));
	}

	/**
	 * wp.deletePost(blogid, username, password, postid) — different order from
	 * the blogger dialect above, and getting it wrong reads as an auth failure.
	 *
	 * @param array<int,mixed> $params
	 */
	public function wpDeletePost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 1, 2);

		return $this->delete($identity, $collection, (string)($params[3] ?? ''), (string)($params[0] ?? ''));
	}

	private function delete(XmlRpcIdentity $identity, ?string $collection, string $postId, string $blogId = ''): bool
	{
		$this->auth->assertOperation($identity, 'DELETE');

		$blog = $this->resolveBlog($identity, $collection, $blogId);

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		return $this->objectRemover->deleteObject($blog->id, $postId);
	}

	/**
	 * Patch, never replace.
	 *
	 * `ObjectUpdater::updateObject()` builds a fresh object from the array it is
	 * given, so using it here would wipe `image`, `gallery`, `featured`, `media`
	 * and every custom field — WordPress's struct has no concept of them. A
	 * text-only edit from a writing app must leave an admin-set hero image alone.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function applyEdit(string $collection, string $postId, array $fields): void
	{
		// `id` is never patched: it is the storage location, and the mapper
		// already drops wp_slug on edits. Belt and braces.
		unset($fields['id']);

		if ($fields === []) {
			return;
		}

		$this->objectPatcher->patchObject($collection, $postId, $fields);
	}

	/**
	 * Append a numeric suffix until the id is free, matching what the admin does
	 * when two posts share a title.
	 */
	private function uniqueId(string $collection, string $candidate): string
	{
		if (!$this->objectFetcher->existsObject($collection, $candidate)) {
			return $candidate;
		}

		$suffix = 2;
		while ($this->objectFetcher->existsObject($collection, $candidate . '-' . $suffix)) {
			$suffix++;

			if ($suffix > 500) {
				throw XmlRpcFault::forbidden('Could not allocate a unique post id.');
			}
		}

		return $candidate . '-' . $suffix;
	}

	private function publishFlag(mixed $flag): bool
	{
		if (is_bool($flag)) {
			return $flag;
		}

		if (is_numeric($flag)) {
			return (int)$flag === 1;
		}

		return true;
	}

	private function resolveBlog(XmlRpcIdentity $identity, ?string $collection, string $blogId = ''): CollectionData
	{
		if ($collection !== null) {
			return $this->registry->assertBlog($identity, $collection);
		}

		if ($blogId !== '') {
			return $this->registry->assertBlog($identity, $blogId);
		}

		$blogs = $this->registry->blogsFor($identity);
		if ($blogs === []) {
			throw XmlRpcFault::notFound('This API key has access to no blog collections.');
		}

		return reset($blogs);
	}
}
