<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

final class ObjectSaver
{
	public function __construct(
		private ObjectRepository $storage,
		private ObjectFactory $factory,
		private IndexBuilder $indexBuilder,
	) {
	}

	/**
	 * @param array<string,mixed> $objectData
	 */
	public function saveObject(string $collection, array $objectData): ObjectData
	{
		$object = $this->factory->generateObject($collection, $objectData);

		if ($this->storage->existsObject($collection, $object->id)) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $object->id, $collection));
		}

		$this->storage->saveObject($collection, $object);

		$this->indexBuilder->buildIndex($collection);

		return $object;
	}
}
