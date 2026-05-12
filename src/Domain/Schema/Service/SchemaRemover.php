<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\SchemaEventPayload;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
readonly class SchemaRemover
{
	public function __construct(
		private SchemaRepository $storage,
		private CollectionLister $collectionLister,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * delete a schema.
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
		$this->schemaIsInherited($id);

		$result = $this->storage->deleteSchema($id);

		if ($result) {
			$this->eventDispatcher->dispatch('schema.deleted', new SchemaEventPayload($id));
		}

		return $result;
	}

	/**
	 * Check if a schema is inherited by other schemas.
	 *
	 * @throws \DomainException if schema is inherited
	 */
	private function schemaIsInherited(string $schemaId): void
	{
		$inheritingSchemas = $this->storage->findInheritingSchemas($schemaId);
		if ($inheritingSchemas !== []) {
			$schemaList = implode(', ', $inheritingSchemas);
			throw new \DomainException("Unable to delete schema ({$schemaId}). It is inherited by: {$schemaList}", 1);
		}
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
