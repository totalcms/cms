<?php

namespace TotalCMS\Domain\Collection\Service;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Schema\Data\SchemaData;

final class CollectionFactory
{
	private Serializer $serializer;

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/**
	 * Generate Collection object.
	 *
	 * @param array<string,mixed> $data The collection data to save
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return CollectionData
	 */
	public function generateCollection(array $data): CollectionData
	{
		$collection = $this->serializer->denormalize($data, CollectionData::class);

		if (!$collection instanceof CollectionData || !$collection->isValid()) {
			throw new \UnexpectedValueException('Invalid Collection data provided');
		}

		return $collection;
	}

	/**
	 * Generate Collection object.
	 *
	 * @param string $json The collection data to save. This should be json encoded.
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return CollectionData
	 */
	public function generateCollectionFromJson(string $json): CollectionData
	{
		$collection = $this->serializer->deserialize($json, CollectionData::class, 'json');

		if (!$collection->isValid()) {
			throw new \UnexpectedValueException('Invalid Collection data provided');
		}

		return $collection;
	}

	/**
	 * Generate a reserved schema Collection object.
	 *
	 * @param string $collectionId The collection id to save
	 *
	 * @throws \DomainException
	 *
	 * @return CollectionData
	 */
	public function generateReservedCollection(string $collectionId): CollectionData
	{
		if (!in_array($collectionId, SchemaData::RESERVED_SCHEMAS)) {
			throw new \DomainException("Cannot generate collection $collectionId. No reserved schema found.");
		}

		$collection         = new CollectionData();
		$collection->id     = $collectionId;
		$collection->schema = $collectionId;

		return $collection;
	}
}
