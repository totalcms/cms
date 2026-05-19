<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Migration\Repository\MigrationStateRepository;

/**
 * Runs registered migrations that haven't yet been applied on this install.
 * Failures are logged and left unrecorded so they retry next time — the
 * common failure mode is transient (file locked, partial filesystem state)
 * and silently skipping past a failed migration is worse than retrying.
 */
readonly class MigrationRunner
{
	/**
	 * @param iterable<MigrationInterface> $migrations
	 */
	public function __construct(
		private iterable $migrations,
		private MigrationStateRepository $state,
		private LoggerInterface $logger,
	) {
	}

	public function runPending(): void
	{
		foreach ($this->migrations as $migration) {
			$id = $migration->id();
			if ($this->state->hasRun($id)) {
				continue;
			}

			try {
				$result = $migration->run();
				$this->state->recordRan($id, $result);
				if ($result > 0) {
					$this->logger->info('Migration applied', [
						'id'          => $id,
						'description' => $migration->description(),
						'result'      => $result,
					]);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Migration failed', [
					'id'    => $id,
					'error' => $e->getMessage(),
				]);
			}
		}
	}
}
