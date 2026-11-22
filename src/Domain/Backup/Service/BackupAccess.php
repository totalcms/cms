<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;

/**
 * Who may look at backup history and restore from it in the admin.
 *
 * History shows the old value of every field, so reading it takes the right to
 * UPDATE, not just to read. On an owned collection that means a member sees
 * only the history of objects they own. A deleted object there has no owner
 * left to check a member against, so its history and the collection's list of
 * deleted objects are for admins.
 *
 * The page itself is a utility, granted per access group, and that is checked
 * before any of this (`UtilsAccessMiddleware`). The restore is an ordinary
 * save, so the write gate checks it once more.
 */
final readonly class BackupAccess
{
	public function __construct(
		private ActorContext $actors,
		private AccessGuard $guard,
	) {
	}

	/** May the actor pick this collection at all: do their groups grant update on it? */
	public function reaches(string $collection): bool
	{
		return $collection !== '' && $this->guard->groupAllows($this->actors->current(), $collection, Operation::Update->groupOperation());
	}

	/** May the actor see the collection's deleted objects? */
	public function deleted(string $collection): bool
	{
		return $collection !== '' && $this->guard->can($this->actors->current(), $collection, Operation::Update) === Decision::Allow;
	}

	/** May the actor see this object's history? */
	public function object(string $collection, string $id): bool
	{
		return $collection !== '' && $id !== '' && $this->guard->can($this->actors->current(), $collection, Operation::Update, $id) === Decision::Allow;
	}

	/** May the actor restore from it? Bringing back a deleted object also takes the right to create. */
	public function restore(string $collection, string $id, bool $live): bool
	{
		return $this->object($collection, $id)
			&& ($live || $this->guard->can($this->actors->current(), $collection, Operation::Create) === Decision::Allow);
	}
}
