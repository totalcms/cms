<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\JumpStart\Data\ImportReport;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * One import in flight: its report and the two switches every section
 * consults.
 *
 * `$upsert` is sync mode — the payload is authoritative and existing
 * objects are overwritten, which is also what gates the pre-overwrite
 * backups; a starter-kit import never overwrites and needs none.
 *
 * `$allowSystemCollections` lets the import write code-executing system
 * collections (`automations`, whose handler is unsandboxed PHP — a direct
 * RCE vector). Only callers that established a super-admin operator or run
 * with shell trust opt in, mirroring SystemCollectionGuardMiddleware's rule
 * for the generic write path, so every write surface enforces the same
 * "super-admin only" policy.
 */
final readonly class ImportRun
{
	public function __construct(
		public ImportReport $report,
		public bool $upsert,
		public bool $allowSystemCollections,
	) {
	}

	/**
	 * Refuse a write targeting a code-executing system collection unless
	 * this import was authorized for it. Records the error and returns true
	 * when the write must be skipped, so the rest of the import proceeds and
	 * the caller still sees a failure result.
	 */
	public function refuseSystemCollection(string $id, string $kind): bool
	{
		if ($this->allowSystemCollections || !SchemaData::isSystemCollection($id)) {
			return false;
		}

		$this->report->error(sprintf(
			'%s %s: refused — the "%s" collection executes code and can only be imported by a super-admin',
			$kind,
			$id,
			$id,
		));

		return true;
	}
}
