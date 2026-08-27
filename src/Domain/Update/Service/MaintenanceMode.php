<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Service;

use TotalCMS\Support\Config;

/**
 * Manages maintenance mode during updates.
 */
readonly class MaintenanceMode
{
	private string $flagFile;

	public function __construct(Config $config)
	{
		$this->flagFile = $config->cachedir . '/maintenance.flag';
	}

	/**
	 * Raise the maintenance flag.
	 *
	 * Throws rather than returning a bool: every caller is a step in an update
	 * that is about to swap the application directory, and proceeding without
	 * the flag means serving a half-swapped install with no maintenance page.
	 * The write is silenced because a failure here is an environment problem
	 * reported through this exception, not a PHP warning worth capturing.
	 */
	public function enable(): void
	{
		if (@file_put_contents($this->flagFile, (string)time()) === false) {
			throw new \RuntimeException(
				"Cannot enable maintenance mode: {$this->flagFile} could not be written. "
				. 'Check that the cache directory exists and is writable by the user running the update. '
				. 'The update was aborted before any files were changed.'
			);
		}
	}

	public function disable(): void
	{
		if (file_exists($this->flagFile)) {
			@unlink($this->flagFile);
		}
	}

	public function isEnabled(): bool
	{
		return file_exists($this->flagFile);
	}
}
