<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Resets date fields on objects based on schema settings (onCreate, onUpdate).
 * Used by ObjectSaver and ObjectCloner to ensure correct timestamps.
 */
readonly class DateFieldResetter
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
	) {
	}

	public function resetOnCreateFields(ObjectData $object, string $collection): void
	{
		$fields = $this->getDateFieldsBySetting($collection, DateData::CREATION_DATE);
		$this->resetDateFields($object, $fields);
	}

	public function resetOnUpdateFields(ObjectData $object, string $collection): void
	{
		$fields = $this->getDateFieldsBySetting($collection, DateData::UPDATE_DATE);
		$this->resetDateFields($object, $fields);
	}

	/**
	 * @param array<string> $fields
	 */
	private function resetDateFields(ObjectData $object, array $fields): void
	{
		$currentDate = DateData::cleanDate();

		foreach ($fields as $fieldName) {
			if ($object->properties->has($fieldName) && $object->properties->get($fieldName) instanceof DateData) {
				/** @var DateData $dateProperty */
				$dateProperty       = $object->properties->get($fieldName);
				$dateProperty->date = $currentDate;
			}
		}
	}

	/** @return array<string> */
	private function getDateFieldsBySetting(string $collection, string $setting): array
	{
		$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$dateFields = [];

		foreach ($schema->properties as $fieldName => $fieldDefinition) {
			$isDateField = (
				(isset($fieldDefinition['type']) && $fieldDefinition['type'] === 'date')
				|| (isset($fieldDefinition['$ref']) && str_contains((string)$fieldDefinition['$ref'], '/date.json'))
			);

			$hasSetting = (
				(isset($fieldDefinition[$setting]) && $fieldDefinition[$setting] === true)
				|| (isset($fieldDefinition['settings'][$setting]) && $fieldDefinition['settings'][$setting] === true)
			);

			if ($isDateField && $hasSetting) {
				$dateFields[] = $fieldName;
			}
		}

		return $dateFields;
	}
}
