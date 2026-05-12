<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

readonly class SaverFactory
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher,
		private ObjectSaver $objectSaver,
		private SchemaFetcher $schemaFetcher,
		private ObjectPatcher $objectPatcher,
		private ObjectFetcher $objectFetcher,
		private LoggerFactory $loggerFactory,
		private Config $config,
		private PropertyMetaResolver $metaResolver,
	) {
	}

	public function generateSaverService(
		string $collection,
		string $property,
		string $objectId = '',
		?string $subpath = null,
	): FileSaver {
		// When the upload targets a child of a card or deck-item, resolve the
		// child's type from the parent's schemaref instead of the parent's own
		// type. The parent (e.g. `card`) has no Saver class — children
		// (image/file/etc.) do.
		//
		// Depot/gallery are the exceptions: those parents own the storage
		// hierarchy themselves and use `subpath` for folder paths, NOT for
		// nested-child keys. Their own savers (DepotSaver/GallerySaver) handle
		// the subpath internally.
		if ($subpath !== null && $subpath !== '') {
			$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$parentDef  = $schema->properties[$property] ?? [];
			$parentType = PropertyDefinition::fromArray($parentDef)->resolveType();

			if (in_array($parentType, ['depot', 'gallery'], true)) {
				$type     = $parentType;
				$settings = $this->metaResolver->resolveSettings($collection, $property, $objectId);
			} else {
				$childKey = $this->resolveChildKey($subpath);
				$type     = $this->resolveNestedChildType($collection, $property, $childKey);
				$settings = $this->metaResolver->resolveNestedSettings($collection, $property, $childKey);
			}
		} else {
			$schema   = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$type     = PropertyDefinition::fromArray($schema->properties[$property])->resolveType();
			$settings = $this->metaResolver->resolveSettings($collection, $property, $objectId);
		}

		$className = 'TotalCMS\\Domain\\Property\\Service\\' . ucfirst($type) . 'Saver';
		if (!class_exists($className)) {
			throw new \UnexpectedValueException('Unknown saver service type for object.');
		}

		$saver = new $className(
			$this->storage,
			$this->propFetcher,
			$this->objectSaver,
			$this->objectPatcher,
			$this->objectFetcher,
			$this->loggerFactory,
			$this->config,
		);

		if (!$saver instanceof FileSaver) {
			throw new \DomainException('Error creating file saver service.');
		}

		$saver->setSettings($settings);

		return $saver;
	}

	/**
	 * Extract the child property key from a subpath. For Phase 2 (cards) the
	 * subpath is a single segment that IS the child key. For Phase 3 (decks)
	 * the subpath will look like `{itemId}/{childKey}` — the last segment wins.
	 */
	private function resolveChildKey(string $subpath): string
	{
		$pos = strrpos($subpath, '/');

		return $pos === false ? $subpath : substr($subpath, $pos + 1);
	}

	private function resolveNestedChildType(string $collection, string $parentProperty, string $childKey): string
	{
		$childMeta = $this->metaResolver->resolveNested($collection, $parentProperty, $childKey);

		return PropertyDefinition::fromArray($childMeta)->resolveType();
	}
}
