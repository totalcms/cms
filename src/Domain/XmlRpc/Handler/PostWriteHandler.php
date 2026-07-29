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
			'wp.newPost'          => $this->wpNewPost(...),
			'wp.editPost'         => $this->wpEditPost(...),
		];
	}

	/**
	 * metaWeblog.newPost(blogid, username, password, struct, publish).
	 *
	 * @param array<int,mixed> $params
	 */
	public function newPost(array $params, ?string $collection): string
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'POST');

		$blog   = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$struct = is_array($params[3] ?? null) ? $params[3] : [];
		// A create with no publish flag still publishes — that is WordPress's
		// behavior for newPost — so the "not supplied" case is resolved to
		// `true` explicitly here, rather than the mapper inventing a value.
		$fields = $this->mapper->toObject($struct, $this->requestedPublishFlag($params, 4) ?? true, true);
		$fields = $this->finalizeNewPost($fields, $blog->id, $identity);

		return $this->objectSaver->saveObject($blog->id, $fields)->id;
	}

	/**
	 * metaWeblog.editPost(postid, username, password, struct, publish).
	 *
	 * @param array<int,mixed> $params
	 */
	public function editPost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'PUT');

		$postId = (string)($params[0] ?? '');
		// editPost carries no blogid — resolveForPost() locates the post by
		// searching the collections this key can see, rather than guessing which
		// blog was meant (the bug that let one blog's post be overwritten by an
		// edit meant for another).
		$blog = $this->registry->resolveForPost($identity, $collection, $postId);

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		$struct = is_array($params[3] ?? null) ? $params[3] : [];
		// Unlike newPost, an omitted publish flag on edit must NOT be treated as
		// "publish": a client that sends only a title (or an empty struct) must
		// leave the post's current draft/published state alone. `null` here
		// means "the client sent nothing" and toObject() will drop `draft`
		// entirely rather than inventing a value.
		$fields = $this->mapper->toObject($struct, $this->requestedPublishFlag($params, 4), false);

		$this->applyEdit($blog->id, $postId, $fields);

		return true;
	}

	/**
	 * blogger.deletePost(appkey, postid, username, password, publish).
	 *
	 * Carries no blogid at all, so the blog is resolved by finding the post —
	 * guessing here is exactly what let this call delete the wrong post out of
	 * the wrong collection.
	 *
	 * @param array<int,mixed> $params
	 */
	public function bloggerDeletePost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 2, 3);
		$this->auth->assertOperation($identity, 'DELETE');

		$postId = (string)($params[1] ?? '');
		$blog   = $this->registry->resolveForPost($identity, $collection, $postId);

		return $this->deleteFrom($blog, $postId);
	}

	/**
	 * wp.deletePost(blogid, username, password, postid) — different order from
	 * the blogger dialect above, and getting it wrong reads as an auth failure.
	 * This dialect DOES carry a blogid, so it stays on the ordinary resolver.
	 *
	 * @param array<int,mixed> $params
	 */
	public function wpDeletePost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'DELETE');

		$postId = (string)($params[3] ?? '');
		$blog   = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));

		return $this->deleteFrom($blog, $postId);
	}

	/**
	 * wp.newPost(blog_id, username, password, content_struct).
	 *
	 * Unlike metaWeblog.newPost, this dialect carries no separate publish
	 * parameter — `post_status` inside the struct is the only signal — so
	 * `true` is passed for the same reason metaWeblog.newPost resolves an
	 * absent flag to `true`: a create with no status said at all still
	 * publishes, matching WordPress's own newPost default.
	 *
	 * @param array<int,mixed> $params
	 */
	public function wpNewPost(array $params, ?string $collection): string
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'POST');

		$blog   = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$struct = is_array($params[3] ?? null) ? $params[3] : [];
		// A create has no existing post to consult, so `hasExtendedEntry` is
		// always false here — see fromWpStruct()'s docblock for why that means
		// the whole body lands in `content` with no split.
		$fields = $this->mapper->fromWpStruct($struct, true, true, false);
		$fields = $this->finalizeNewPost($fields, $blog->id, $identity);

		return $this->objectSaver->saveObject($blog->id, $fields)->id;
	}

	/**
	 * wp.editPost(blog_id, username, password, post_id, content_struct).
	 *
	 * Unlike metaWeblog.editPost, this dialect DOES carry a blog_id, so it
	 * resolves via resolveFor() rather than searching for the post. `null` is
	 * passed for publish so an edit that never mentions `post_status` leaves
	 * the post's current draft/published state alone — same rule as
	 * metaWeblog.editPost, just with no separate flag to distinguish
	 * "omitted" from "sent" in the first place.
	 *
	 * @param array<int,mixed> $params
	 */
	public function wpEditPost(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'PUT');

		$blog   = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$postId = (string)($params[3] ?? '');

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		$struct = is_array($params[4] ?? null) ? $params[4] : [];
		// Only a post that already has its own extended entry is eligible for
		// the content/extra split below — an inline `<!--more-->` marker in an
		// imported post's `content` (empty `extra`) is not ours to interpret.
		// See PostMapper::fromWpStruct() for the full reasoning.
		$fields = $this->mapper->fromWpStruct($struct, null, false, $this->hasExtendedEntry($blog->id, $postId));

		$this->applyEdit($blog->id, $postId, $fields);

		return true;
	}

	private function deleteFrom(CollectionData $blog, string $postId): bool
	{
		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		return $this->objectRemover->deleteObject($blog->id, $postId);
	}

	/**
	 * Whether the post already stored under $postId carries a non-empty
	 * `extra` field. Callers already verified the post exists before calling
	 * this (wp.editPost's not-found check runs first), so a plain fetch is
	 * safe here.
	 */
	private function hasExtendedEntry(string $collection, string $postId): bool
	{
		$extra = (string)($this->objectFetcher->fetchObject($collection, $postId)->toArray()['extra'] ?? '');

		return trim($extra) !== '';
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
	 * Finalize a create's field set with a unique id and an author fallback —
	 * shared by both dialects' "new post" methods so a slug rule or an
	 * attribution fallback can never quietly diverge between them.
	 *
	 * @param array<string,mixed> $fields
	 *
	 * @return array<string,mixed>
	 */
	private function finalizeNewPost(array $fields, string $collection, XmlRpcIdentity $identity): array
	{
		$id = is_string($fields['id'] ?? null) && $fields['id'] !== ''
			? $fields['id']
			: $this->mapper->titleSlug((string)($fields['title'] ?? ''));

		$fields['id']     = $this->uniqueId($collection, $id);
		$fields['author'] ??= $identity->authorName;

		return $fields;
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

	/**
	 * Whether the client actually sent a publish flag at this position — `null`
	 * when the param is genuinely absent (a shorter param list) or sent as an
	 * empty string, distinct from a value that resolves to `false`. An empty
	 * string is not a client asking to publish; it is XML-RPC's usual shape for
	 * "no value here" (an empty `<string></string>`), so it is treated the same
	 * as an absent param rather than falling through to `publishFlag()`'s
	 * default of `true`. Distinguishing "not supplied" from "supplied as false"
	 * is exactly what protects a draft from being silently published by an edit
	 * that only touches text.
	 *
	 * @param array<int,mixed> $params
	 */
	private function requestedPublishFlag(array $params, int $index): ?bool
	{
		if (!array_key_exists($index, $params)) {
			return null;
		}

		$flag = $params[$index];
		if ($flag === '') {
			return null;
		}

		return $this->publishFlag($flag);
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
}
