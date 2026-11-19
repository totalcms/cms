<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Support\Config;

/**
 * What the admin collection table may show the signed-in user, behind
 * `cms.admin.collectionTable.*`. Both answers come from the rules the API
 * routes apply ({@see AccessGuard}), so the table never offers what the
 * server refuses or counts what it hides.
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
	 * Whether the signed-in user may bulk delete in the collection: the group
	 * grant to delete, and never as a member scoped to their own objects.
	 */
	public function canBulkDelete(string $collection): bool
	{
		return !$this->config->authEnabled()
			|| $this->guard->can($this->actorResolver->fromSession(), $collection, Operation::BulkDelete)->passes();
	}
}
