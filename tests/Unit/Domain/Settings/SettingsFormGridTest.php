<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every settings schema that defines a `formgrid` must place every one of its
 * own properties on that grid, and must not name anything that is not a
 * property.
 *
 * This is a silent-failure guard, not a style check. TotalForm only falls back
 * to "one row per field" when `formgrid` is absent — once a schema defines one,
 * the grid IS the field list, so a property left off it simply does not render.
 * The setting stays in `config/defaults.php`, keeps working, and becomes
 * invisible in Admin → Settings with nothing anywhere reporting a problem. The
 * reverse (a grid cell naming a property that was renamed or removed) leaves a
 * dead area in the CSS layout.
 *
 * Neither shows up in any other test, and both are one typo away whenever a
 * setting is added.
 */
final class SettingsFormGridTest extends TestCase
{
	/**
	 * @return array<string,array{string}>
	 */
	public static function settingsSchemaProvider(): array
	{
		$cases = [];

		foreach (glob(__DIR__ . '/../../../../resources/schemas/settings/*.json') ?: [] as $path) {
			$cases[basename($path)] = [$path];
		}

		return $cases;
	}

	/**
	 * `FormField::$min` and `$max` are typed `int`, so a fractional bound in a
	 * schema is silently floored on the way in — and PHP 8.1+ only whispers
	 * about it as a deprecation, which does not fail a run.
	 *
	 * That is a quiet correctness bug, not a cosmetic one: `subscriptionStreamSeconds`
	 * was written with `"min": 0.25` and reached the admin as `min="0"`, offering
	 * the operator the single value that means "never close this stream" to the
	 * MCP SDK.
	 *
	 * @dataProvider settingsSchemaProvider
	 */
	public function testNumericBoundsAreIntegers(string $path): void
	{
		$schema = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($schema, "{$path} is not valid JSON.");

		$properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

		foreach ($properties as $name => $spec) {
			if (!is_array($spec)) {
				continue;
			}

			foreach (['min', 'max'] as $bound) {
				if (!isset($spec[$bound]) || !is_float($spec[$bound])) {
					continue;
				}

				$this->assertSame(
					floor($spec[$bound]),
					$spec[$bound],
					sprintf(
						'%s: %s.%s is %s. FormField casts it to int, so the admin would enforce %d '
						. 'instead — pick an integer bound, or the field will accept values the code rejects.',
						basename($path),
						$name,
						$bound,
						var_export($spec[$bound], true),
						(int)$spec[$bound],
					),
				);
			}
		}

		$this->assertTrue(true);
	}

	/**
	 * @dataProvider settingsSchemaProvider
	 */
	public function testFormGridPlacesEveryPropertyExactlyOnce(string $path): void
	{
		$schema = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($schema, "{$path} is not valid JSON.");

		$formgrid = $schema['formgrid'] ?? '';
		if (!is_string($formgrid) || trim($formgrid) === '') {
			// No grid means TotalForm renders one field per row — nothing to check.
			$this->assertTrue(true);

			return;
		}

		$properties = array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []);
		$placed     = [];

		foreach (explode("\n", $formgrid) as $line) {
			$line = trim($line);

			// `---`, `---Title---` and `--- Title` are section markers.
			if ($line === '' || str_starts_with($line, '---')) {
				continue;
			}

			foreach (preg_split('/\s+/', $line) ?: [] as $cell) {
				// `.` is a deliberately empty grid cell.
				if ($cell !== '' && $cell !== '.') {
					$placed[$cell] = true;
				}
			}
		}

		$placed  = array_keys($placed);
		$missing = array_values(array_diff($properties, $placed));
		$unknown = array_values(array_diff($placed, $properties));

		$this->assertSame(
			[],
			$missing,
			sprintf(
				'%s defines a formgrid that omits %s — those settings will not render in the admin at all. '
				. 'Add them to the grid or drop them from the schema.',
				basename($path),
				implode(', ', $missing),
			),
		);

		$this->assertSame(
			[],
			$unknown,
			sprintf(
				'%s has a formgrid naming %s, which is not a property — a renamed or removed setting '
				. 'leaves a dead area in the layout.',
				basename($path),
				implode(', ', $unknown),
			),
		);
	}
}
