<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Support\Config;

/**
 * What the admin collection table shows the signed-in user, behind
 * `cms.admin.collectionTable.*`: its starting sort, and what it may list and
 * offer. The access answers come from the rules the API routes apply
 * ({@see AccessGuard}), so the table never offers what the server refuses or
 * counts what it hides.
 *
 * On an owned collection a member's table lists only their own objects
 * (the `/query` route is scoped), and bulk delete is refused to them.
 */
readonly class CollectionTableAccess
{
	public function __construct(
		private Config $config,
		private AccessGuard $guard,
		private ActorResolver $actorResolver,
		private IndexQueryService $indexQuery,
	) {
	}

	/**
	 * How many objects of the collection the signed-in user can list, or null
	 * when they can list them all: the collection's own total then applies,
	 * and the index is not loaded.
	 */
	public function visibleObjectCount(string $collection): ?int
	{
		if (!$this->config->authEnabled()) {
			return null;
		}

		$scope = $this->guard->readScope($this->actorResolver->fromSession(), $collection);
		if ($scope->isAll()) {
			return null;
		}

		return $this->indexQuery->query($collection, ['limit' => '1'], $scope)->total;
	}

	/**
	 * The table's starting `sort` for the `/query` route, from the collection's
	 * Sort By, every rule with an explicit direction: `-date,title` gives
	 * `date:desc,title:asc`.
	 *
	 * Sort By takes the query's sort syntax, so a single property stored before
	 * it did (`date`) still reads as itself. `shuffle` is dropped: the table
	 * would reshuffle on every page. Nothing left falls back to `id`.
	 */
	public function sort(string $sortBy): string
	{
		$rules = [];
		foreach (SortRuleParser::parse($sortBy) as $rule) {
			$property = $rule['property'] ?? '';
			if (!is_string($property) || $property === '') {
				continue;
			}

			$rules[] = $property . ':' . (($rule['reverse'] ?? false) === true ? 'desc' : 'asc') . (($rule['natural'] ?? false) === true ? ':natural' : '');
		}

		return $rules !== [] ? implode(',', $rules) : 'id:asc';
	}

	/**
	 * Whether the signed-in user may bulk delete in the collection: the group
	 * grant to delete, and never as a member scoped to their own objects.
	 */
	public function canBulkDelete(string $collection): bool
	{
		return !$this->config->authEnabled()
			|| $this->guard->can($this->actorResolver->fromSession(), $collection, Operation::BulkDelete)->passes();
	}
}
