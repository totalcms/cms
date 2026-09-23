<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\JumpStart\Data\ImportReport;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/** The `schemas` section of a JumpStart definition. */
readonly class SchemaSection
{
	public function __construct(
		private SchemaSaver $schemaSaver,
		private BackupStore $syncBackup,
	) {
	}

	/** @param array<int,array<string,mixed>> $schemas */
	public function import(array $schemas, ImportRun $run): void
	{
		foreach ($schemas as $schema) {
			$id = (string)($schema['id'] ?? 'unknown');
			if ($run->refuseSystemCollection((string)($schema['id'] ?? ''), 'Schema')) {
				continue;
			}
			try {
				// saveSchema overwrites unconditionally; in sync mode, snapshot
				// the existing version first so the overwrite has an undo.
				if ($run->upsert) {
					$this->syncBackup->backupSchema((string)($schema['id'] ?? ''));
				}
				// preserveDates: an imported schema keeps its source's updated
				// timestamp — restamping would make the copy read newer than
				// the original (the same rule as collections and objects).
				$this->schemaSaver->saveSchema($schema, preserveDates: true);
				$run->report->result(ImportReport::SCHEMAS, sprintf('Schema %s: created', $id));
			} catch (\Exception $e) {
				$run->report->error(sprintf('Schema %s: %s', $id, $e->getMessage()));
			}
		}
	}
}
