<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

readonly class ObjectCloner
{
	public function __construct(
		private ObjectRepository $storage,
		private IndexBuilder $indexBuilder,
		private CollectionSaver $collectionSaver,
		private DateFieldResetter $dateFieldResetter,
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
		$this->dateFieldResetter->resetOnCreateFields($object, $to['collection']);
		$this->dateFieldResetter->resetOnUpdateFields($object, $to['collection']);

		$this->storage->saveObject($to['collection'], $object);

		$this->storage->copyObjectFiles($from['collection'], $from['id'], $to['collection'], $to['id']);

		// Increment the collection count since we've added a new object
		$this->collectionSaver->incrementCount($to['collection']);

		// Increment totalObjects and update lastUpdated
		$this->collectionSaver->incrementTotalObjects($to['collection']);

		// Pass the cloned object for immediate index append when queueRebuildOnSave is enabled
		$this->indexBuilder->smartBuildIndex($to['collection'], $object);

		return $object;
	}
}
