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

	public function enable(): void
	{
		file_put_contents($this->flagFile, (string) time());
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
