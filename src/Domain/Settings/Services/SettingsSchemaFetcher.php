<?php

namespace TotalCMS\Domain\Settings\Services;

/**
 * Fetches settings schemas for form building.
 */
readonly class SettingsSchemaFetcher
{
	private const SCHEMAS_PATH = __DIR__ . '/../../../../resources/schemas';

	/**
	 * Get schema for a settings section.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getSchema(string $section): ?array
	{
		$schemaPath = self::SCHEMAS_PATH . '/settings/' . $section . '.json';

		if (!file_exists($schemaPath)) {
			return null;
		}

		$content = file_get_contents($schemaPath);
		if ($content === false) {
			return null;
		}

		$schema = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return null;
		}

		return is_array($schema) ? $schema : null;
	}

	/**
	 * Get properties from a settings schema.
	 *
	 * @return array<string,mixed>
	 */
	public function getProperties(string $section): array
	{
		$schema = $this->getSchema($section);

		return $schema['properties'] ?? [];
	}

	/**
	 * Check if a settings schema exists.
	 */
	public function schemaExists(string $section): bool
	{
		$schemaPath = self::SCHEMAS_PATH . '/settings/' . $section . '.json';

		return file_exists($schemaPath);
	}
}
