<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LoggerFactory;

readonly class JumpStartExporter
{
	private LoggerInterface $logger;

	public function __construct(
		private CollectionLister $collectionLister,
		private SchemaLister $schemaLister,
		private SchemaFetcher $schemaFetcher,
		private ObjectFetcher $objectFetcher,
		private IndexReader $indexReader,
		private TemplateLister $templateLister,
		private TemplateFetcher $templateFetcher,
		private JumpStartData $jumpstart,
		private CacheManager $cacheManager,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('jumpstart.log')->createLogger('jumpstart-exporter');
	}

	public function setMetadata(string $name = '', string $description = ''): void
	{
		if ($name !== '') {
			$this->jumpstart->setName($name);
		}
		if ($description !== '') {
			$this->jumpstart->setDescription($description);
		}
	}

	/**
	 * Export current CMS data to jumpstart definition.
	 */
	public function exportCurrentData(): JumpStartData
	{
		$this->logger->info('Starting jumpstart export');

		// Make sure we do not have any cached data
		$this->cacheManager->clearAllCaches();

		$this->exportCustomSchemas();
		$this->exportCollections();
		$this->exportObjects();
		$this->exportTemplates();

		$this->logger->info('Completed jumpstart export', [
			'schemas'              => count($this->jumpstart->schemas),
			'reserved_collections' => count($this->jumpstart->collections['reserved']),
			'custom_collections'   => count($this->jumpstart->collections['custom']),
			'objects'              => count($this->jumpstart->objects),
			'templates'            => count($this->jumpstart->templates),
		]);

		return $this->jumpstart;
	}

	private function exportCustomSchemas(): void
	{
		$schemas = $this->schemaLister->listCustomSchemas();

		foreach ($schemas as $schema) {
			$this->jumpstart->addSchema($schema->toArray());
		}
	}

	private function exportCollections(): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			if (in_array($collection->schema, SchemaData::RESERVED_SCHEMAS)) {
				$this->jumpstart->addReservedCollection($collection->schema);
				continue;
			}
			$this->jumpstart->addCustomCollection($collection->toArray());
		}
	}

	private function exportObjects(): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			$this->logger->info('Exporting objects from collection', [
				'collection' => $collection->id,
			]);

			// Use IndexReader to get all object IDs for this collection
			$index = $this->indexReader->fetchIndex($collection->id);

			$objectCount = 0;
			foreach ($index->objects as $object) {
				$object        = $this->objectFetcher->fetchObject($collection->id, $object['id']);
				$processedData = $this->processObjectData($object, $collection->schema);

				$this->jumpstart->addObject([
					'collection' => $collection->id,
					'id'         => $object->id,
					'data'       => $processedData,
				]);
				$objectCount++;
			}

			$this->logger->info('Completed object export for collection', [
				'collection'       => $collection->id,
				'objects_exported' => $objectCount,
			]);
		}
	}

	/** @return array<string,mixed> */
	private function processObjectData(ObjectData $object, string $schemaId): array
	{
		$schema        = $this->schemaFetcher->fetchSchema($schemaId);
		$processedData = $object->toArray();

		// Process each field according to its schema type
		foreach ($schema->properties as $fieldName => $property) {
			if (isset($processedData[$fieldName])) {
				$fieldType = $property['field'] ?? $property['type'] ?? '';

				switch ($fieldType) {
					case 'image':
					case 'gallery':
						// Normalize image and gallery properties to their respective types
						$processedData[$fieldName] = $fieldType;
						break;

					case 'depot':
					case 'file':
						// Normalize depot and file properties to empty arrays
						$processedData[$fieldName] = [];
						break;
				}
			}
		}
		unset($processedData['id']);

		return $processedData;
	}

	private function exportTemplates(): void
	{
		$this->logger->info('Exporting templates');

		// Export custom templates only (not reserved templates)
		$templates = $this->templateLister->listCustomTemplates(null, true);

		foreach ($templates as $templatePath) {
			try {
				$template = $this->templateFetcher->fetchTemplate($templatePath);

				$this->jumpstart->addTemplate([
					'id'       => $template->id,
					'template' => $template->contents,
				]);
			} catch (\Exception $e) {
				$this->logger->warning('Failed to export template', [
					'path'  => $templatePath,
					'error' => $e->getMessage(),
				]);
			}
		}

		$this->logger->info('Completed template export', [
			'templates_exported' => count($this->jumpstart->templates),
		]);
	}
}
