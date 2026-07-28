<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\XmlRpc\Service\BlogRegistry;
use TotalCMS\Domain\XmlRpc\Service\XmlRpcAuth;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;
use TotalCMS\Support\Config;

/**
 * Categories and tags.
 *
 * T3 has no taxonomy store: `propertyOptions: true` means these fields offer
 * "unique values already used in this collection", so every list here is derived
 * from the index. That is also why `wp.newCategory` cannot persist anything —
 * a category with no posts has nowhere to live.
 *
 * Category ids are the names themselves. XML-RPC carries them as strings, so
 * unlike the WP REST API this needs no synthetic integer term registry.
 */
readonly class TaxonomyHandler implements MethodHandler
{
	public function __construct(
		private XmlRpcAuth $auth,
		private BlogRegistry $registry,
		private IndexFilter $indexFilter,
		private ObjectFetcher $objectFetcher,
		private ObjectPatcher $objectPatcher,
		private Config $config,
	) {
	}

	/** @return array<string,callable(array<int,mixed>,?string):mixed> */
	public function methods(): array
	{
		return [
			'metaWeblog.getCategories' => $this->getCategories(...),
			'mt.getCategoryList'       => $this->getCategoryList(...),
			'wp.getCategories'         => $this->getCategories(...),
			'wp.getTags'               => $this->getTags(...),
			'mt.getPostCategories'     => $this->getPostCategories(...),
			'mt.setPostCategories'     => $this->setPostCategories(...),
			'wp.newCategory'           => $this->newCategory(...),
		];
	}

	/**
	 * metaWeblog.getCategories(blogid, username, password)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getCategories(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$base = rtrim($this->config->api, '/');

		$categories = [];
		foreach ($this->distinctValues($blog->id, 'categories') as $name) {
			// `description` is a free-text field an author writes in WordPress,
			// distinct from the category's name. T3 has no such field —
			// categories are bare strings derived from posts, nothing more is
			// stored — so leaving this blank is the honest representation.
			// Do NOT "restore" it to $name: that would invent content the
			// operator never wrote.
			$categories[] = [
				'categoryId'   => $name,
				'parentId'     => '0',
				'categoryName' => $name,
				'description'  => '',
				'htmlUrl'      => $base,
				'rssUrl'       => $base,
			];
		}

		return $categories;
	}

	/**
	 * mt.getCategoryList(blogid, username, password) — the slimmer struct.
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,string>>
	 */
	public function getCategoryList(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));

		return array_map(
			static fn (string $name): array => ['categoryId' => $name, 'categoryName' => $name],
			$this->distinctValues($blog->id, 'categories')
		);
	}

	/**
	 * wp.getTags(blogid, username, password)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getTags(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog = $this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));
		$base = rtrim($this->config->api, '/');

		$tags = [];
		foreach ($this->distinctValues($blog->id, 'tags') as $name) {
			$tags[] = [
				'tag_id'   => $name,
				'name'     => $name,
				'slug'     => $name,
				'count'    => 0,
				'html_url' => $base,
				'rss_url'  => $base,
			];
		}

		return $tags;
	}

	/**
	 * mt.getPostCategories(postid, username, password)
	 *
	 * @param array<int,mixed> $params
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getPostCategories(array $params, ?string $collection): array
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'GET');

		$blog   = $this->registry->resolveFor($identity, $collection);
		$postId = (string)($params[0] ?? '');

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		$object     = $this->objectFetcher->fetchObject($blog->id, $postId)->toArray();
		$categories = is_array($object['categories'] ?? null) ? $object['categories'] : [];

		$structs = [];
		foreach (array_values($categories) as $index => $name) {
			$structs[] = [
				'categoryId'   => (string)$name,
				'categoryName' => (string)$name,
				'isPrimary'    => $index === 0,
			];
		}

		return $structs;
	}

	/**
	 * mt.setPostCategories(postid, username, password, categories[])
	 *
	 * @param array<int,mixed> $params
	 */
	public function setPostCategories(array $params, ?string $collection): bool
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'PUT');

		$blog   = $this->registry->resolveFor($identity, $collection);
		$postId = (string)($params[0] ?? '');

		if ($postId === '' || !$this->objectFetcher->existsObject($blog->id, $postId)) {
			throw XmlRpcFault::notFound(sprintf('Post "%s" was not found.', $postId));
		}

		$sent  = is_array($params[3] ?? null) ? $params[3] : [];
		$names = [];

		foreach ($sent as $entry) {
			// Clients send either a bare name or a {categoryId, categoryName} struct.
			$name = is_array($entry)
				? (string)($entry['categoryName'] ?? $entry['categoryId'] ?? '')
				: (string)$entry;

			$name = trim($name);
			if ($name !== '') {
				$names[] = $name;
			}
		}

		$this->applyCategories($blog->id, $postId, $names);

		return true;
	}

	/**
	 * wp.newCategory(blogid, username, password, struct)
	 *
	 * Returns the name rather than persisting anything: there is no taxonomy
	 * store, so the category comes into existence the moment a post uses it.
	 * Returning the name keeps ids consistent with getCategories, which also
	 * uses names as ids.
	 *
	 * @param array<int,mixed> $params
	 */
	public function newCategory(array $params, ?string $collection): string
	{
		$identity = $this->auth->authenticate($params, 1, 2);
		$this->auth->assertOperation($identity, 'POST');
		$this->registry->resolveFor($identity, $collection, (string)($params[0] ?? ''));

		$struct = is_array($params[3] ?? null) ? $params[3] : [];
		$name   = trim((string)($struct['name'] ?? ''));

		if ($name === '') {
			throw XmlRpcFault::forbidden('A category name is required.');
		}

		return $name;
	}

	/** @param array<int,string> $names */
	private function applyCategories(string $collection, string $postId, array $names): void
	{
		// Patch so a taxonomy-only write leaves every other field alone.
		$this->objectPatcher->patchObject($collection, $postId, ['categories' => array_values($names)]);
	}

	/**
	 * Distinct values of an indexed list property, sorted for stable client
	 * display.
	 *
	 * @return array<int,string>
	 */
	private function distinctValues(string $collection, string $property): array
	{
		$values = [];

		foreach ($this->indexFilter->fetchFilteredIndex($collection) as $row) {
			$raw = $row[$property] ?? null;

			if (is_string($raw)) {
				$raw = [$raw];
			}

			if (!is_array($raw)) {
				continue;
			}

			foreach ($raw as $value) {
				$value = trim((string)$value);
				if ($value !== '' && !in_array($value, $values, true)) {
					$values[] = $value;
				}
			}
		}

		sort($values);

		return $values;
	}
}
