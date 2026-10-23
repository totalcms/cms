<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Change some of a collection's settings and keep the rest, as
 * `tcms collection:update` and the MCP `update_collection` tool do.
 *
 * Settings that are maps merge one key at a time, so a caller sends only the
 * keys it changes, and a key set to null removes it. Field overrides
 * (`properties`) merge one level deeper: changing a field's label keeps its
 * other overrides. Every other setting is replaced by the value sent. The
 * merged record is saved through CollectionSaver::patchCollection(), which
 * validates it as a save from the collection form does.
 *
 * Callers add their own gates: who may change which collection, and the
 * `mcp.tools` check ({@see McpToolsValidator::settingsError()}).
 */
final readonly class CollectionSettingsUpdater
{
	/** Counters and timestamps the site keeps itself; dropped from an update. */
	private const MANAGED_KEYS = ['count', 'totalObjects', 'lastUpdated', 'updated'];

	/** Settings maps an update merges into one key at a time. */
	public const MAP_KEYS = ['properties', 'customProperties', 'formSettings', 'mcp', 'seo', 'sitemap', 'feed'];

	public function __construct(
		private CollectionSaver $saver,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Merge $settings into $collection's settings and save them.
	 *
	 * @param array<string,mixed> $settings
	 *
	 * @throws \DomainException          a rename, a format change, or settings the save rejects
	 * @throws \UnexpectedValueException settings the save rejects
	 */
	public function update(CollectionData $collection, array $settings): CollectionData
	{
		if (isset($settings['id']) && $settings['id'] !== $collection->id) {
			throw new \DomainException('A collection cannot be renamed. Create a new collection instead.');
		}

		$stored = $collection->toArray();
		$patch  = array_diff_key($settings, array_flip([...self::MANAGED_KEYS, 'id']));
		foreach ($patch as $key => $value) {
			if (in_array($key, self::MAP_KEYS, true) && is_array($value) && !array_is_list($value)) {
				$patch[$key] = self::mergeMap(is_array($stored[$key] ?? null) ? $stored[$key] : [], $value, $key === 'properties');
			}
		}
		if (is_array($patch['properties'] ?? null)) {
			$patch['properties'] = $this->withFieldTypes($collection->schema, $patch['properties']);
		}

		return $this->saver->patchCollection($collection->id, $patch);
	}

	/**
	 * Field overrides with the `field` each one must carry (they validate as
	 * schema properties), taken from the schema where the caller left it out.
	 *
	 * @param array<mixed> $overrides
	 *
	 * @return array<mixed>
	 */
	private function withFieldTypes(string $schemaId, array $overrides): array
	{
		try {
			$properties = $this->schemaFetcher->fetchSchema($schemaId)->properties;
		} catch (\UnexpectedValueException|\DomainException) {
			return $overrides;
		}

		foreach ($overrides as $name => $override) {
			$field = $properties[$name]['field'] ?? null;
			if (is_array($override) && !isset($override['field']) && is_string($field)) {
				$overrides[$name] = ['field' => $field] + $override;
			}
		}

		return $overrides;
	}

	/**
	 * $edit laid over $stored one key at a time; a null removes the key.
	 * With $deep (field overrides), each field's override merges the same
	 * way, so changing a label keeps the field's other overrides.
	 *
	 * @param array<mixed> $stored
	 * @param array<mixed> $edit
	 *
	 * @return array<mixed>
	 */
	private static function mergeMap(array $stored, array $edit, bool $deep = false): array
	{
		foreach ($edit as $key => $value) {
			if ($value === null) {
				unset($stored[$key]);
			} elseif ($deep && is_array($value) && !array_is_list($value) && is_array($stored[$key] ?? null)) {
				$stored[$key] = self::mergeMap($stored[$key], $value);
			} else {
				$stored[$key] = $value;
			}
		}

		return $stored;
	}
}
