<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Settings\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Support\PathResolver;

/**
 * Repository for managing settings.json in tcms-data/.system/.
 *
 * Also exposes the canonical list of available settings sections, derived
 * from the schema files in `resources/schemas/settings/`. The repository
 * is the single source of truth for both "what sections are available"
 * and "what values are stored" — the SettingsValidator and the admin
 * settings UI both consume this catalog.
 */
class SettingsRepository extends StorageRepository
{
	private const SETTINGS_FILE = '.system/settings.json';
	private const BACKUP_FILE   = '.system/settings.json.bak';

	/**
	 * Request-level cache for settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $requestCache = null;

	/**
	 * Request-level cache for the section catalog.
	 *
	 * @var list<string>|null
	 */
	private ?array $sectionsCache = null;

	/**
	 * Load all settings from settings.json.
	 *
	 * @return array<string,mixed>
	 */
	public function load(): array
	{
		if ($this->requestCache !== null) {
			return $this->requestCache;
		}

		if (!$this->filesystem->fileExists(self::SETTINGS_FILE)) {
			$this->requestCache = [];

			return $this->requestCache;
		}

		$content = $this->filesystem->read(self::SETTINGS_FILE);
		if ($content === '') {
			$this->requestCache = [];

			return $this->requestCache;
		}

		$settings = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->requestCache = [];

			return $this->requestCache;
		}

		$this->requestCache = is_array($settings) ? $settings : [];

		return $this->requestCache;
	}

	/**
	 * Save settings to settings.json.
	 *
	 * @param array<string,mixed> $settings
	 */
	public function save(array $settings): void
	{
		// Write settings as JSON (Flysystem automatically creates parent directories)
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode settings to JSON: ' . json_last_error_msg());
		}

		// Snapshot the previous file so an unexpected clobber is one rename away from recovery.
		if ($this->filesystem->fileExists(self::SETTINGS_FILE)) {
			$this->filesystem->write(self::BACKUP_FILE, $this->filesystem->read(self::SETTINGS_FILE));
		}

		$this->filesystem->write(self::SETTINGS_FILE, $json);

		// Invalidate cache after write
		$this->requestCache = null;
	}

	/**
	 * Check if settings.json exists.
	 */
	public function exists(): bool
	{
		return $this->filesystem->fileExists(self::SETTINGS_FILE);
	}

	/**
	 * List all settings sections that have a schema file.
	 *
	 * The section name is the schema file's basename without `.json`. Adding
	 * a new file at `resources/schemas/settings/{name}.json` registers a new
	 * section without any code changes — `SettingsValidator::isValidSection()`
	 * and the admin settings UI pick it up automatically.
	 *
	 * Uses native `glob()` rather than the flysystem adapter because the
	 * schema directory lives under packageRoot (resources), not datadir.
	 *
	 * @return list<string>
	 */
	public function listSections(): array
	{
		if ($this->sectionsCache !== null) {
			return $this->sectionsCache;
		}

		$dir = PathResolver::packageRoot() . '/resources/schemas/settings';
		if (!is_dir($dir)) {
			$this->sectionsCache = [];

			return $this->sectionsCache;
		}

		$sections = [];
		foreach (glob($dir . '/*.json') ?: [] as $path) {
			$sections[] = basename($path, '.json');
		}

		sort($sections);
		$this->sectionsCache = $sections;

		return $this->sectionsCache;
	}
}
