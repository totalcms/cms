<?php

namespace TotalCMS\Domain\Settings\Services;

/**
 * Fetches settings schemas for form building.
 */
class SettingsSchemaFetcher
{
	private const SCHEMAS_PATH = __DIR__ . '/../../../../resources/schemas';

	/**
	 * Request-level cache for schemas.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $requestCache = [];

	/**
	 * Get schema for a settings section.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getSchema(string $section): ?array
	{
		if (array_key_exists($section, $this->requestCache)) {
			return $this->requestCache[$section];
		}

		$schemaPath = self::SCHEMAS_PATH . '/settings/' . $section . '.json';

		if (!file_exists($schemaPath)) {
			$this->requestCache[$section] = null;

			return null;
		}

		$content = file_get_contents($schemaPath);
		if ($content === false) {
			$this->requestCache[$section] = null;

			return null;
		}

		$schema = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->requestCache[$section] = null;

			return null;
		}

		$this->requestCache[$section] = is_array($schema) ? $schema : null;

		return $this->requestCache[$section];
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
