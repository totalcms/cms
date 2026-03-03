<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * Resolves property metadata by merging schema → collection → per-object overrides.
 *
 * Extracts the resolution logic previously embedded in ObjectForm so that
 * both the form system and SaverFactory can obtain fully-merged settings.
 */
readonly class PropertyMetaResolver
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private CollectionFetcher $collectionFetcher,
		private Config $config,
	) {
	}

	/**
	 * Return fully-merged meta props for a property.
	 *
	 * Merge order: schema → collection.properties → collection.customProperties[objectId].
	 * The `settings` key receives special treatment: presets are resolved at each layer
	 * and, if still empty, a type-default preset is tried.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve(string $collection, string $property, string $objectId = ''): array
	{
		$schemaData     = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		$schemaProp     = $schemaData->properties[$property] ?? [];
		$collectionProp = $collectionData !== null ? ($collectionData->properties[$property] ?? []) : [];
		$customProp     = ($objectId !== '' && $collectionData !== null)
			? ($collectionData->customProperties[$objectId][$property] ?? [])
			: [];

		// Merge the non-settings keys
		$merged = array_merge($schemaProp, $collectionProp, $customProp);

		// Resolve settings with preset handling at each layer
		$merged['settings'] = $this->resolveFieldSettings(
			$schemaProp['settings'] ?? [],
			$collectionProp['settings'] ?? [],
			$customProp['settings'] ?? [],
			$merged['field'] ?? '',
		);

		return $merged;
	}

	/**
	 * Convenience — returns just the resolved `settings` array.
	 *
	 * @return array<string,mixed>
	 */
	public function resolveSettings(string $collection, string $property, string $objectId = ''): array
	{
		$result = $this->resolve($collection, $property, $objectId);

		$settings = $result['settings'] ?? [];

		return is_array($settings) ? $settings : [];
	}

	/**
	 * If settings contain a "preset" key, load the named preset as the base
	 * and merge any additional explicit settings on top.
	 *
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	public function resolvePreset(array $settings): array
	{
		if (!isset($settings['preset']) || !is_string($settings['preset'])) {
			return $settings;
		}

		$presetName = $settings['preset'];
		unset($settings['preset']);

		$preset = $this->config->presets[$presetName] ?? [];

		// Deck format stores presets as {id, settings}, extract the settings
		$presetValues = is_array($preset['settings'] ?? null) ? $preset['settings'] : $preset;

		if (!is_array($presetValues) || $presetValues === []) {
			return $settings;
		}

		// Preset is the base, explicit settings override
		return array_merge($presetValues, $settings);
	}

	/**
	 * Look up a type-default preset matching the field type name.
	 *
	 * If a preset exists with a name matching the field type (e.g., "styledtext"),
	 * it automatically applies as the default settings for all fields of that type
	 * that have no explicit settings.
	 *
	 * @return array<string,mixed>
	 */
	public function resolveTypePreset(string $fieldType): array
	{
		$preset = $this->config->presets[$fieldType] ?? [];

		// Deck format stores presets as {id, settings}, extract the settings
		$presetValues = is_array($preset['settings'] ?? null) ? $preset['settings'] : $preset;

		if (!is_array($presetValues) || $presetValues === []) {
			return [];
		}

		return $presetValues;
	}

	/**
	 * Resolve the full settings for a field property, including presets.
	 *
	 * Merges settings from schema, collection, and custom levels.
	 * Resolves named presets at each level and falls back to type-default presets.
	 *
	 * @param array<string,mixed> $schemaSettings
	 * @param array<string,mixed> $collectionSettings
	 * @param array<string,mixed> $customSettings
	 *
	 * @return array<string,mixed>
	 */
	private function resolveFieldSettings(
		array $schemaSettings,
		array $collectionSettings,
		array $customSettings,
		string $fieldType,
	): array {
		// Resolve preset references at each level
		$schemaSettings     = $this->resolvePreset($schemaSettings);
		$collectionSettings = $this->resolvePreset($collectionSettings);
		$customSettings     = $this->resolvePreset($customSettings);

		$settings = array_merge($schemaSettings, $collectionSettings, $customSettings);

		// If no settings exist at any level, check for a type-default preset
		if ($settings === [] && $fieldType !== '') {
			$settings = $this->resolveTypePreset($fieldType);
		}

		return $settings;
	}
}
