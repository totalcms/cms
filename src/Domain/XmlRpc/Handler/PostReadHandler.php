<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
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
		$blog   = $this->resolveBlog($identity, $collection);

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

		$blog  = $this->resolveBlog($identity, $collection, (string)($params[0] ?? ''));
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

		$blog  = $this->resolveBlog($identity, $collection, (string)($params[0] ?? ''));
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

	private function resolveBlog(XmlRpcIdentity $identity, ?string $collection, string $blogId = ''): CollectionData
	{
		// URL-pinned collection wins; blogid is ignored entirely on that route,
		// which is what makes us immune to clients that hardcode blogid=1.
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

		// Single-blog sites: pick the only one rather than faulting on a client
		// that omitted blogid.
		return reset($blogs);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function recentRows(CollectionData $blog, int $count): array
	{
		$sortBy = $blog->sortBy !== '' ? $blog->sortBy : 'date';

		$result = $this->queryService->query($blog->id, [
			'limit' => (string)$count,
			'sort'  => $sortBy . ':desc',
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
