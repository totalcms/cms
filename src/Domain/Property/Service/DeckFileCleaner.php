<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

/**
 * Diff-on-save cleanup for files/images uploaded inside deck items.
 *
 * Browser-side deck-item removal is purely DOM — files referenced by the removed
 * item stay on disk until the parent object is saved. After each `object.updated`
 * event this service compares the previous and current state and, for every
 * deck item that disappeared, deletes the whole item directory in one shot
 * (which sweeps every nested file/image at once). Per-child changes inside a
 * surviving item are intentionally not touched — same-key replacement is
 * already handled by FileSaver before the form save lands here.
 */
readonly class DeckFileCleaner
{
	private LoggerInterface $logger;

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private PropertyRepository $repository,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('deck-file-cleaner');
	}

	public function cleanup(string $collection, string $objectId, ObjectData $previous, ObjectData $current): void
	{
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not fetch schema for orphan cleanup', [
				'collection' => $collection,
				'object'     => $objectId,
				'error'      => $e->getMessage(),
			]);

			return;
		}

		$previousArray = $previous->toArray();
		$currentArray  = $current->toArray();

		foreach ($schema->properties as $propertyName => $propertyConfig) {
			if (!is_array($propertyConfig)) {
				continue;
			}
			if (PropertyDefinition::fromArray($propertyConfig)->resolveType() !== 'deck') {
				continue;
			}

			$prev = $previousArray[$propertyName] ?? null;
			if (!is_array($prev)) {
				continue;
			}
			$curr = is_array($currentArray[$propertyName] ?? null) ? $currentArray[$propertyName] : [];

			foreach (array_keys($prev) as $itemId) {
				if (array_key_exists($itemId, $curr)) {
					continue;
				}
				$this->safeDeleteDirectory($collection, $objectId, $propertyName, (string)$itemId);
			}
		}
	}

	private function safeDeleteDirectory(
		string $collection,
		string $objectId,
		string $propertyName,
		string $subpath,
	): void {
		try {
			if (!$this->repository->directoryExists($collection, $objectId, $propertyName, $subpath)) {
				return;
			}
			$this->repository->deleteDirectory($collection, $objectId, $propertyName, null, $subpath);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to delete orphaned deck item directory', [
				'collection' => $collection,
				'object'     => $objectId,
				'property'   => $propertyName,
				'subpath'    => $subpath,
				'error'      => $e->getMessage(),
			]);
		}
	}
}
