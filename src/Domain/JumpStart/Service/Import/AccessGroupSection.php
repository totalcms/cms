<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\AccessGroup\Service\AccessGroupEditor;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\JumpStart\Data\ImportReport;

/**
 * The `accessGroups` section of a JumpStart definition. Additive only: a
 * group that already exists is never touched, and `admin` is never written.
 *
 * An existing group is reported rather than skipped silently, because the
 * same id can mean something else on this site (a `members` group that
 * grants more, or less, than the JumpStart's).
 *
 * A group is a permission grant, so only an import trusted with system
 * collections (a super admin or the shell, see ImportRun) may create one.
 */
readonly class AccessGroupSection
{
	public function __construct(
		private AccessGroupLister $lister,
		private AccessGroupEditor $editor,
	) {
	}

	/** @param array<int,mixed> $groups */
	public function import(array $groups, ImportRun $run): void
	{
		if ($groups === []) {
			return;
		}

		if (!$run->allowSystemCollections) {
			$run->report->error('Access groups: refused — creating access groups grants permissions, so only a super-admin can import them');

			return;
		}

		foreach ($groups as $group) {
			$id = is_array($group) ? (string)($group['id'] ?? '') : '';
			if ($id === '') {
				$run->report->error('Access group: entry missing id');

				continue;
			}

			if ($id === 'admin' || $this->lister->exists($id)) {
				$run->report->result(null, sprintf('Access group %s: exists, kept', $id));

				continue;
			}

			try {
				$this->editor->set($id, $group);
				$run->report->result(ImportReport::ACCESS_GROUPS, sprintf('Access group %s: created', $id));
			} catch (\Exception $e) {
				$run->report->error(sprintf('Access group %s: %s', $id, $e->getMessage()));
			}
		}
	}
}
