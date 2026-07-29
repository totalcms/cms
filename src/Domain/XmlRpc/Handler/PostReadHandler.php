<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\XmlRpc\Service\BlogRegistry;
use TotalCMS\Domain\XmlRpc\Service\PostMapper;
use TotalCMS\Domain\XmlRpc\Service\XmlRpcAuth;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

/**
 * Read methods — what makes a client open showing your existing posts rather
 * than an empty list.
 */
readonly class PostReadHandler implements MethodHandler
{
	/**
	 * Upper bound on any "recent posts" request. Clients ask for very large
	 * counts (and `-1` meaning "all"); without a clamp that loads an entire
	 * collection to populate a client-side list.
	 */
	public const MAX_POSTS = 100;

	public function __construct(
		private XmlRpcAuth $auth,
		private BlogRegistry $registry,
		private PostMapper $mapper,
		private IndexQueryService $queryService,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/** @return array<string,callable(array<int,mixed>,?string):mixed> */
	public function methods(): array
	{
		return [
			'metaWeblog.getPost'        => $this->getPost(...),
			'metaWeblog.getRecentPosts' => $this->getRecentPosts(...),
			'mt.getRecentPostTitles'    => $this->getRecentPostTitles(...),
			'wp.getPosts'               => $this->wpGetPosts(...),
			'wp.getPost'                => $this->wpGetPost(...),
			'wp.getPostTypes'           => $this->wpGetPostTypes(...),
			'wp.getPostStatusList'      => $this->wpGetPostStatusList(...),
			'wp.getPostFormats'         => $this->wpGetPostFormats(...),
		];
	}

	/**
	 * metaWeblog.getPost(postid, username, password)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public function getPost(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$postId = (string)($params[0] ?? '');
		// getPost carries no blogid at all — resolveForPost() locates the post by
		// searching the collections this key can see, rather than guessing which
		// blog was meant. On the URL-pinned route it still just pins the
		// collection, so the existence check below still applies there.
		$blog = $this->registry->resolveForPost($identity, $collection, $postId);

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		return $this->mapper->toStruct(
			$this->objectFetcher->fetchObject($blog->id, $postId)->toArray(),
			$blog
		);
	}

	/**
	 * metaWeblog.getRecentPosts(blogid, username, password, numberOfPosts)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getRecentPosts(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog  = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$count = $this->clampCount($this->requestedCount($params[3] ?? null));

		$structs = [];
		foreach ($this->recentIds($blog, $count) as $id) {
			// The index omits `content`, so a full object read is required to
			// populate `description`. Bounded by MAX_POSTS above.
			if ($this->objectFetcher->existsObject($blog->id, $id)) {
				$structs[] = $this->mapper->toStruct(
					$this->objectFetcher->fetchObject($blog->id, $id)->toArray(),
					$blog
				);
			}
		}

		return $structs;
	}

	/**
	 * mt.getRecentPostTitles(blogid, username, password, numberOfPosts)
	 *
	 * Index-only: titles and dates are indexed, so no object reads are needed.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getRecentPostTitles(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog  = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$count = $this->clampCount($this->requestedCount($params[3] ?? null));

		$titles = [];
		foreach ($this->recentRows($blog, $count) as $row) {
			$struct   = $this->mapper->toStruct($row, $blog);
			$titles[] = [
				'postid'      => $struct['postid'],
				'title'       => $struct['title'],
				'userid'      => $struct['wp_author_display_name'],
				'dateCreated' => $struct['dateCreated'],
			];
		}

		return $titles;
	}

	/**
	 * wp.getPosts(blog_id, username, password, filter?, fields?)
	 *
	 * MarsEdit's "Download all posts" setting pages this with `number` +
	 * `offset` — 50 at a time by default — so getting the clamp and the
	 * offset math right is what keeps it from re-fetching the same page (or
	 * an empty one) forever. `fields` (5th param) is accepted and ignored:
	 * the full struct is always returned, same simplification `wp.getOptions`
	 * already makes for its own filter param.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function wpGetPosts(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog   = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$filter = is_array($params[3] ?? null) ? $params[3] : [];

		$limit  = $this->clampCount($this->requestedCount($filter['number'] ?? null));
		$offset = max(0, $this->requestedCount($filter['offset'] ?? null));
		$status = is_string($filter['post_status'] ?? null) ? strtolower(trim($filter['post_status'])) : '';

		// orderby/order are accepted and ignored — every read method in this
		// dialect has a fixed contract with the client (newest first), same
		// rationale as recentRows() below.
		$result = $this->queryService->query($blog->id, [
			'limit'   => (string)$limit,
			'offset'  => (string)$offset,
			'sort'    => 'date:desc',
			'include' => $this->wpStatusFilter($status),
		]);

		$structs = [];
		foreach ($result->items as $row) {
			$id = (string)($row['id'] ?? '');
			// The index omits `content`, so a full object read is required to
			// populate `post_content`, same as getRecentPosts() above.
			if ($id !== '' && $this->objectFetcher->existsObject($blog->id, $id)) {
				$structs[] = $this->mapper->toWpStruct($this->objectFetcher->fetchObject($blog->id, $id)->toArray(), $blog);
			}
		}

		return $structs;
	}

	/**
	 * wp.getPost(blog_id, username, password, post_id, fields?)
	 *
	 * Unlike metaWeblog.getPost, this dialect DOES carry a blog_id, so it
	 * resolves via resolveFor() rather than searching for the post.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public function wpGetPost(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog   = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$postId = (string)($params[3] ?? '');

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		return $this->mapper->toWpStruct($this->objectFetcher->fetchObject($blog->id, $postId)->toArray(), $blog);
	}

	/**
	 * wp.getPostTypes(blog_id, username, password, filter?, fields?)
	 *
	 * T3 has exactly one post type. `filter`/`fields` are accepted and
	 * ignored — there is nothing to filter down to.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function wpGetPostTypes(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');
		$this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));

		return [
			'post' => [
				'name'         => 'post',
				'label'        => 'Posts',
				'public'       => true,
				'hierarchical' => false,
			],
		];
	}

	/**
	 * wp.getPostStatusList(blog_id, username, password)
	 *
	 * Only the two states T3 actually has. `pending`/`private`/`future` are
	 * deliberately NOT advertised — a client offering one of those in its UI
	 * would silently produce a draft when the operator picked something else.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,string>
	 */
	public function wpGetPostStatusList(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');
		$this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));

		return ['draft' => 'Draft', 'publish' => 'Published'];
	}

	/**
	 * wp.getPostFormats(blog_id, username, password)
	 *
	 * T3 has no post formats at all. Advertising only `standard` — never
	 * `aside`, `gallery`, `video`, etc. — keeps a client from offering a
	 * format picker whose selection would silently do nothing.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<string,string>
	 */
	public function wpGetPostFormats(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');
		$this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));

		return ['standard' => 'Standard'];
	}

	/**
	 * Translate wp.getPosts' `filter['post_status']` into an ObjectFilter
	 * `include` expression against the index's boolean `draft` field. An
	 * unrecognized or absent status returns '' (no filter — extractFilterOptions()
	 * treats an empty string the same as the key being absent), matching the
	 * brief's "absent → all" rule.
	 */
	private function wpStatusFilter(string $status): string
	{
		return match ($status) {
			'draft'   => 'draft:true',
			'publish' => 'draft:false',
			default   => '',
		};
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function recentRows(CollectionData $blog, int $count): array
	{
		// Deliberately NOT $blog->sortBy — that setting is an admin display
		// preference for the collection listing (and defaults to 'id', not
		// ''), whereas these RPC methods have a fixed contract with the client:
		// most recently published first, always. `date` is present in the
		// index for both blog schemas, so it is always available here.
		$result = $this->queryService->query($blog->id, [
			'limit' => (string)$count,
			'sort'  => 'date:desc',
		]);

		return $result->items;
	}

	/** @return array<int,string> */
	private function recentIds(CollectionData $blog, int $count): array
	{
		return array_values(array_filter(array_map(
			static fn (array $row): string => (string)($row['id'] ?? ''),
			$this->recentRows($blog, $count)
		), static fn (string $id): bool => $id !== ''));
	}

	private function requestedCount(mixed $requested): int
	{
		return is_numeric($requested) ? (int)$requested : 0;
	}

	private function clampCount(int $count): int
	{
		if ($count <= 0) {
			return self::MAX_POSTS;
		}

		return min($count, self::MAX_POSTS);
	}
}
