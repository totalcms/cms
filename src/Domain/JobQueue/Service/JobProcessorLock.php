<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Reports whether `tcms jobs:process` is currently running.
 *
 * JobsProcessCommand holds an exclusive flock on
 * `<datadir>/.system/.processJobs.lock` for the entire duration of a run. We
 * probe that lock non-blockingly: if we can't acquire it, a processor holds it
 * (running); if we can, nothing is running. This lets the health check
 * distinguish a stalled queue from one a long-running drain is actively
 * working through.
 */
readonly class JobProcessorLock
{
	private const LOCK_FILE = '.system/.processJobs.lock';

	public function __construct(
		private Config $config,
	) {
	}

	public function isRunning(): bool
	{
		$lockPath = PathUtils::absolutePath($this->config->datadir, self::LOCK_FILE);

		$handle = @fopen($lockPath, 'c');
		if ($handle === false) {
			// Can't open the lock file — can't prove a processor is running, so
			// don't suppress the warning on that basis.
			return false;
		}

		$acquired = flock($handle, LOCK_EX | LOCK_NB);
		if ($acquired) {
			flock($handle, LOCK_UN);
			fclose($handle);

			return false;
		}

		fclose($handle);

		return true;
	}
}
