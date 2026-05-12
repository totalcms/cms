<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Setup\Repository;

use TotalCMS\Domain\Setup\Data\SetupState;
use TotalCMS\Support\Config;

/**
 * Reads and writes the durable wizard state file.
 *
 * Path: `<datadir>/.system/setup-state.json`
 *
 * Reads return null when the file is absent, the datadir doesn't exist,
 * or the contents are unreadable/malformed JSON. Writes are no-ops when
 * the datadir doesn't exist (e.g. while the user is still on the
 * environment step, before they've picked a data path).
 *
 * Why this repo doesn't extend StorageRepository like the others:
 * StorageFilesystemAdapter is bound to Config::datadir at container
 * construction. The data-path step mutates Config::datadir mid-request
 * (DataPathInstaller does this so completeStep can write to the right
 * place), and the storage adapter would then be pointing at the old
 * (just-moved) location for the rest of the request — re-creating a
 * ghost directory there on the next write. Routing this one file
 * through Config directly avoids that whole class of problem without
 * adding a "rebind the storage adapter" hook that no other repo needs.
 */
readonly class SetupStateRepository
{
	private const RELATIVE_PATH = '.system/setup-state.json';

	public function __construct(
		private Config $config,
	) {
	}

	public function exists(): bool
	{
		if (!$this->dataPathExists()) {
			return false;
		}

		return is_file($this->path());
	}

	public function read(): ?SetupState
	{
		if (!$this->exists()) {
			return null;
		}

		$content = @file_get_contents($this->path());
		if ($content === false || $content === '') {
			return null;
		}

		$decoded = json_decode($content, true);
		if (!is_array($decoded)) {
			return null;
		}

		return SetupState::fromArray($decoded);
	}

	public function write(SetupState $state): void
	{
		if (!$this->dataPathExists()) {
			return;
		}

		$systemDir = $this->config->datadir . '/.system';
		if (!is_dir($systemDir)) {
			@mkdir($systemDir, 0755, true);
		}

		@file_put_contents(
			$this->path(),
			(string)json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);
	}

	private function path(): string
	{
		return $this->config->datadir . '/' . self::RELATIVE_PATH;
	}

	private function dataPathExists(): bool
	{
		return $this->config->datadir !== '' && is_dir($this->config->datadir);
	}
}
