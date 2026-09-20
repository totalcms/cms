<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Settings\Repository;

use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Settings\SettingsSections;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Repository for the settings files in tcms-data/.system/.
 *
 * There are up to two: the shared `settings.json`, and — when this install
 * sets `siteId` — a per-site `settings-{siteId}.json` layered over it.
 * `load()` returns the effective merge and is the READ side; `loadBase()` and
 * `loadOverlay()` stay unmerged and are the WRITE side, because a caller that
 * writes back what it loaded must not write back the merge.
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

	/**
	 * Request-level cache per file path.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $requestCache = [];

	/**
	 * Request-level cache for the section catalog.
	 *
	 * @var list<string>|null
	 */
	private ?array $sectionsCache = null;

	/**
	 * @param SettingsSchemaFetcher $schemaFetcher Unused here now that
	 *        sectionKeys() delegates to SettingsSections — kept as a
	 *        constructor parameter because the container wires it and
	 *        existing tests construct the repository with it.
	 */
	public function __construct(
		StorageAdapterInterface $filesystem,
		SettingsSchemaFetcher $schemaFetcher,
		private readonly Config $config,
	) {
		parent::__construct($filesystem);
	}

	/** Is a per-site overlay configured for this install? */
	public function hasOverlay(): bool
	{
		return $this->config->siteId !== '';
	}

	/** The overlay's basename for display, or '' when there is none. */
	public function overlayFilename(): string
	{
		return $this->hasOverlay() ? 'settings-' . $this->config->siteId . '.json' : '';
	}

	private function overlayPath(): string
	{
		return '.system/settings-' . $this->config->siteId . '.json';
	}

	/**
	 * Effective settings: the shared file with the overlay replacing whatever
	 * top-level keys it declares. The READ side — see the class docblock.
	 *
	 * @return array<string,mixed>
	 */
	public function load(): array
	{
		return array_replace(
			$this->loadBase(),
			SettingsSections::filterDeclared($this->loadOverlay(), $this->config->siteSettings),
		);
	}

	/**
	 * The shared settings.json alone, unmerged.
	 *
	 * @return array<string,mixed>
	 */
	public function loadBase(): array
	{
		return $this->readFile(self::SETTINGS_FILE);
	}

	/**
	 * This install's overlay alone, unmerged. Empty when none is configured.
	 *
	 * @return array<string,mixed>
	 */
	public function loadOverlay(): array
	{
		return $this->hasOverlay() ? $this->readFile($this->overlayPath()) : [];
	}

	/** @param array<string,mixed> $settings */
	public function saveBase(array $settings): void
	{
		$this->writeFile(self::SETTINGS_FILE, $settings);
	}

	/** @param array<string,mixed> $settings */
	public function saveOverlay(array $settings): void
	{
		if (!$this->hasOverlay()) {
			throw new \RuntimeException('No settings overlay is configured for this install (siteId is empty).');
		}

		$this->writeFile($this->overlayPath(), $settings);
	}

	/**
	 * The settings-array keys a section owns. General settings are stored at
	 * the top level rather than under a `general` key, so the section maps to
	 * whatever its schema declares — the same derivation SettingsFetcher uses.
	 *
	 * @return list<string>
	 */
	public function sectionKeys(string $section): array
	{
		return SettingsSections::keysFor($section);
	}

	/**
	 * Does this install own the section? Answered by the `siteSettings`
	 * declaration in its tcms.php, NOT by what the overlay file happens to
	 * contain — a declared section is owned from boot, before anything has
	 * been saved into it.
	 */
	public function ownsSection(string $section): bool
	{
		return $this->hasOverlay() && SettingsSections::isDeclared($section, $this->config->siteSettings);
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

	/** @return array<string,mixed> */
	private function readFile(string $path): array
	{
		if (isset($this->requestCache[$path])) {
			return $this->requestCache[$path];
		}

		$this->requestCache[$path] = [];

		if (!$this->filesystem->fileExists($path)) {
			return $this->requestCache[$path];
		}

		$content = $this->filesystem->read($path);
		if ($content === '') {
			return $this->requestCache[$path];
		}

		$decoded = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return $this->requestCache[$path];
		}

		$this->requestCache[$path] = $decoded;

		return $this->requestCache[$path];
	}

	/** @param array<string,mixed> $settings */
	private function writeFile(string $path, array $settings): void
	{
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode settings to JSON: ' . json_last_error_msg());
		}

		// Snapshot the previous file so an unexpected clobber is one rename away
		// from recovery. Each file keeps its own .bak.
		if ($this->filesystem->fileExists($path)) {
			$this->filesystem->write($path . '.bak', $this->filesystem->read($path));
		}

		$this->filesystem->write($path, $json);

		unset($this->requestCache[$path]);
	}
}
