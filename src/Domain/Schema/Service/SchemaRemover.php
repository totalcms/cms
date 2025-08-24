<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final readonly class SchemaRemover
{
	public function __construct(
		private SchemaRepository $storage,
		private CollectionLister $collectionLister,
	) {
	}

	/**
	 * delete a schema.
	 *
	 * @param string $id
	 *
	 * @throws \UnexpectedValueException
	 */
	public function deleteSchema(string $id): bool
	{
		$reserved = $this->storage->reservedSchemasIds();
		if (in_array($id, $reserved)) {
			throw new \UnexpectedValueException("Unable to delete schema type ({$id}) is reserved", 1);
		}
		$this->collectionExistsWithSchema($id);

		return $this->storage->deleteSchema($id);
	}

	private function collectionExistsWithSchema(string $schemaId): bool
	{
		$collections = $this->collectionLister->listAllCollections();
		foreach ($collections as $collection) {
			$schema = $collection->schema ?? '';
			if ($schema === $schemaId) {
				$name = $collection->name ?? '';
				throw new \DomainException("Unable to delete schema ({$schemaId}). It is being used in collection '{$name}'", 1);
			}
		}

		return false;
	}
}
