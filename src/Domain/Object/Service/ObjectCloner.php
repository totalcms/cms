<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

readonly class ObjectCloner
{
	public function __construct(
		private ObjectRepository $storage,
		private IndexBuilder $indexBuilder,
		private SchemaFetcher $schemaFetcher,
		private CollectionSaver $collectionSaver,
	) {
	}

	/**
	 * @param array<string,mixed> $from
	 * @param array<string,mixed> $to
	 */
	public function cloneObject(array $from, array $to): ObjectData
	{
		$object = $this->storage->fetchObject($from['collection'], $from['id']);

		if (!$object instanceof ObjectData) {
			throw new \UnexpectedValueException('Unable to find object to clone');
		}
		$object->id = $to['id'];

		if ($this->storage->existsObject($to['collection'], $to['id'])) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $to['id'], $to['collection']));
		}

		// Reset onCreate and onUpdate date fields to current time
		$this->resetOnCreateDateFields($object, $to['collection']);
		$this->resetOnUpdateDateFields($object, $to['collection']);

		$this->storage->saveObject($to['collection'], $object);

		$this->storage->copyObjectFiles($from['collection'], $from['id'], $to['collection'], $to['id']);

		// Pass the cloned object for immediate index append when queueRebuildOnSave is enabled
		$this->indexBuilder->smartBuildIndex($to['collection'], $object);

		// Increment the collection count since we've added a new object
		$this->collectionSaver->incrementCount($to['collection']);

		return $object;
	}

	private function resetOnCreateDateFields(ObjectData $object, string $collection): void
	{
		$onCreateFields = $this->getDateFieldsBySetting($collection, DateData::CREATION_DATE);
		$this->resetDateFields($object, $onCreateFields);
	}

	private function resetOnUpdateDateFields(ObjectData $object, string $collection): void
	{
		$onUpdateFields = $this->getDateFieldsBySetting($collection, DateData::UPDATE_DATE);
		$this->resetDateFields($object, $onUpdateFields);
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

	/** @return array<string>  */
	private function getDateFieldsBySetting(string $collection, string $setting): array
	{
		$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$dateFields = [];

		foreach ($schema->properties as $fieldName => $fieldDefinition) {
			// Check if this is a date field with the specified setting
			$isDateField = (
				(isset($fieldDefinition['type']) && $fieldDefinition['type'] === 'date')
				|| (isset($fieldDefinition['$ref']) && str_contains($fieldDefinition['$ref'], '/date.json'))
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
