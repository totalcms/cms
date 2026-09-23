<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import\Concerns;

/**
 * The tally every migration importer keeps: queue the object, count it,
 * say so in the log. Expects `$this->jobQueuer` (JobQueuer) and
 * `$this->logger` (LoggerInterface) on the host.
 */
trait QueuesImports
{
	protected int $importCount = 0;

	/** @param array<string,mixed> $data */
	protected function queueObject(string $collection, array $data, string $logMessage): void
	{
		$this->jobQueuer->queueImport($collection, $data);
		$this->importCount++;
		$this->logger->info($logMessage);
	}
}
