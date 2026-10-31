<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Starter\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Settings\SettingsSections;
use TotalCMS\Support\PathResolver;

/**
 * Keeps a starter export free of the source site's secrets.
 *
 * Two jobs. scrub*() blanks the settings values a starter must not carry:
 * any property whose field type is sensitive (`password`, `secret`, from
 * SchemaData::SENSITIVE_FIELD_TYPES) and any string holding the source
 * project's absolute path. knownSecrets() collects every secret the running
 * site holds, so the exporter can check the finished output for a leak the
 * exclusion list missed.
 */
final readonly class StarterSecretScrubber
{
	/** Shorter values match too much ordinary text to be worth checking. */
	private const MIN_SECRET_LENGTH = 12;

	public function __construct(
		private SettingsSchemaFetcher $settingsSchemas,
		private string $bundledExtensionsDir,
	) {
	}

	/**
	 * Blank the sensitive values in a settings.json (or a per-site overlay).
	 *
	 * @param array<string,mixed> $settings
	 * @param list<string>        $scrubbed dotted keys blanked, appended to
	 *
	 * @return array<string,mixed>
	 */
	public function scrubSettings(array $settings, string $projectRoot, array &$scrubbed): array
	{
		foreach ($this->settingsSections() as $section) {
			$properties = $this->settingsSchemas->getProperties($section);
			$keys       = SettingsSections::keysFor($section);

			// General settings live at the top level; every other section under its own key.
			if ($section === 'general') {
				$settings = $this->blankSensitive($settings, $properties, '', $scrubbed);
				continue;
			}

			foreach ($keys as $key) {
				if (is_array($settings[$key] ?? null)) {
					$settings[$key] = $this->blankSensitive($settings[$key], $properties, $key . '.', $scrubbed);
				}
			}
		}

		return $this->blankPaths($settings, $projectRoot, '', $scrubbed);
	}

	/**
	 * Blank the sensitive values in one extension's settings file, using the
	 * settings schema its manifest names.
	 *
	 * @param array<string,mixed> $settings
	 * @param list<string>        $scrubbed
	 *
	 * @return array<string,mixed>
	 */
	public function scrubExtensionSettings(array $settings, string $extensionId, string $datadir, string $projectRoot, array &$scrubbed): array
	{
		$properties = $this->extensionSettingsProperties($extensionId, $datadir);
		$settings   = $this->blankSensitive($settings, $properties, '', $scrubbed);

		return $this->blankPaths($settings, $projectRoot, '', $scrubbed);
	}

	/**
	 * Every secret the source site holds that is long enough to search for:
	 * the site key, API keys, OAuth client secrets, the cron token, and the
	 * values of sensitive settings, core and extension.
	 *
	 * @return list<string>
	 */
	public function knownSecrets(string $datadir): array
	{
		$system  = $datadir . '/.system';
		$secrets = [];

		foreach (['site.key', 'cron-token'] as $file) {
			$secrets[] = trim((string)@file_get_contents($system . '/' . $file));
		}

		// Every string in these files is either a secret or harmless to search for.
		foreach (['apikeys.json', 'oauth-clients.json'] as $file) {
			array_push($secrets, ...$this->strings($this->readJson($system . '/' . $file)));
		}

		$secrets = [...$secrets, ...$this->sensitiveSettingValues($datadir)];
		$secrets = array_filter($secrets, static fn (string $s): bool => strlen($s) >= self::MIN_SECRET_LENGTH);

		return array_values(array_unique($secrets));
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $properties
	 * @param list<string>        $scrubbed
	 *
	 * @return array<string,mixed>
	 */
	private function blankSensitive(array $data, array $properties, string $prefix, array &$scrubbed): array
	{
		foreach ($properties as $name => $definition) {
			if (!is_array($definition) || !array_key_exists($name, $data)) {
				continue;
			}

			if (in_array($definition['field'] ?? null, SchemaData::SENSITIVE_FIELD_TYPES, true)) {
				if ($data[$name] !== '' && $data[$name] !== null) {
					$data[$name] = '';
					$scrubbed[]  = $prefix . $name;
				}
				continue;
			}

			// A card or nested object: its own properties may be sensitive.
			if (is_array($definition['properties'] ?? null) && is_array($data[$name])) {
				$data[$name] = $this->blankSensitive($data[$name], $definition['properties'], $prefix . $name . '.', $scrubbed);
			}
		}

		return $data;
	}

	/**
	 * Blank any string that names a path inside the source project: it means
	 * nothing on the machine the starter is installed on.
	 *
	 * @param array<mixed> $data
	 * @param list<string> $scrubbed
	 *
	 * @return array<mixed>
	 */
	private function blankPaths(array $data, string $projectRoot, string $prefix, array &$scrubbed): array
	{
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->blankPaths($value, $projectRoot, $prefix . $key . '.', $scrubbed);
			} elseif (is_string($value) && $projectRoot !== '' && str_contains($value, $projectRoot)) {
				$data[$key] = '';
				$scrubbed[] = $prefix . $key;
			}
		}

		return $data;
	}

	/** @return list<string> */
	private function sensitiveSettingValues(string $datadir): array
	{
		$scratch = [];
		$values  = [];

		foreach (glob($datadir . '/.system/settings*.json') ?: [] as $file) {
			$original = $this->readJson($file);
			$scrubbed = $this->scrubSettings($original, '', $scratch);
			array_push($values, ...$this->removedStrings($original, $scrubbed));
		}

		foreach (glob($datadir . '/.system/extension-settings/*/*.json') ?: [] as $file) {
			$extensionId = basename(dirname($file)) . '/' . basename($file, '.json');
			$original    = $this->readJson($file);
			$scrubbed    = $this->scrubExtensionSettings($original, $extensionId, $datadir, '', $scratch);
			array_push($values, ...$this->removedStrings($original, $scrubbed));
		}

		return $values;
	}

	/**
	 * The strings in $original that are gone from $scrubbed.
	 *
	 * @param array<mixed> $original
	 * @param array<mixed> $scrubbed
	 *
	 * @return list<string>
	 */
	private function removedStrings(array $original, array $scrubbed): array
	{
		return array_values(array_diff($this->strings($original), $this->strings($scrubbed)));
	}

	/** @return array<string,mixed> */
	private function extensionSettingsProperties(string $extensionId, string $datadir): array
	{
		foreach ([$this->bundledExtensionsDir, $datadir . '/extensions'] as $root) {
			$manifest = $this->readJson($root . '/' . $extensionId . '/extension.json');
			$schema   = $manifest['settings_schema'] ?? null;
			if (!is_string($schema) || $schema === '') {
				continue;
			}

			$properties = $this->readJson($root . '/' . $extensionId . '/' . $schema)['properties'] ?? [];

			return is_array($properties) ? $properties : [];
		}

		return [];
	}

	/** @return list<string> */
	private function settingsSections(): array
	{
		$dir      = PathResolver::packageRoot() . '/resources/schemas/settings';
		$sections = array_map(static fn (string $f): string => basename($f, '.json'), glob($dir . '/*.json') ?: []);
		sort($sections);

		return $sections;
	}

	/**
	 * Every string leaf in a decoded JSON value.
	 *
	 * @param array<mixed> $data
	 *
	 * @return list<string>
	 */
	private function strings(array $data): array
	{
		$out = [];
		array_walk_recursive($data, static function (mixed $value) use (&$out): void {
			if (is_string($value)) {
				$out[] = $value;
			}
		});

		return $out;
	}

	/** @return array<string,mixed> */
	private function readJson(string $path): array
	{
		if (!is_file($path)) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($path), true);

		return is_array($decoded) ? $decoded : [];
	}
}
