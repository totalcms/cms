<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Settings;

use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;

/**
 * Which settings-array keys a settings section owns, and how to reduce an
 * overlay to the sections an install declares in `siteOverrides`.
 *
 * Static because `config/settings.php` needs this answer during bootstrap —
 * before the container exists — and SettingsRepository needs the same answer
 * afterwards. One copy of the rule, two callers.
 */
final class SettingsSections
{
	/** @var list<string>|null Memoized general-schema property names. */
	private static ?array $generalKeys = null;

	/**
	 * The settings-array keys a section owns.
	 *
	 * General settings are stored at the top level rather than under a
	 * `general` key, so that section maps to whatever its schema declares.
	 * Every other section owns the single key of its own name.
	 *
	 * @return list<string>
	 */
	public static function keysFor(string $section): array
	{
		if ($section !== 'general') {
			return [$section];
		}

		self::$generalKeys ??= array_map(
			strval(...),
			array_keys((new SettingsSchemaFetcher())->getProperties('general')),
		);

		return self::$generalKeys;
	}

	/**
	 * Reduce an overlay to the keys owned by the declared sections.
	 *
	 * @param array<string,mixed> $overlay
	 * @param list<string>        $declared
	 *
	 * @return array<string,mixed>
	 */
	public static function filterDeclared(array $overlay, array $declared): array
	{
		$allowed = [];
		foreach ($declared as $section) {
			foreach (self::keysFor((string)$section) as $key) {
				$allowed[$key] = true;
			}
		}

		return array_intersect_key($overlay, $allowed);
	}

	/**
	 * @param list<string> $declared
	 */
	public static function isDeclared(string $section, array $declared): bool
	{
		return in_array($section, $declared, true);
	}
}
