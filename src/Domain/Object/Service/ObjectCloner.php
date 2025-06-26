<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final class ObjectCloner
{
	private ObjectRepository $storage;
	private IndexBuilder $indexBuilder;

	public function __construct(ObjectRepository $storage, IndexBuilder $indexBuilder)
	{
		$this->storage      = $storage;
		$this->indexBuilder = $indexBuilder;
	}

	/**
	 * save a collection object.
	 *
	 * @param array<string,mixed> $from
	 * @param array<string,mixed> $to
	 *
	 * @throws \UnexpectedValueException
	 * @throws \RuntimeException
	 *
	 * @return ObjectData
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

		$this->storage->saveObject($to['collection'], $object);

		$this->storage->copyObjectFiles($from['collection'], $from['id'], $to['collection'], $to['id']);

		// Pass the cloned object for immediate index append when queueRebuildOnSave is enabled
		$this->indexBuilder->smartBuildIndex($to['collection'], $object);

		return $object;
	}
}
