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
				[$type, $settings] = $this->walkNestedPath($collection, $property, $objectId, $parentType, $subpath);
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
	 * Resolve the type and settings of the child a nested upload targets by
	 * walking `$subpath` from the top-level property down, one segment at a time:
	 *
	 *   card  → the next segment is a child in the card's schemaref
	 *   deck  → the next segment is an item id (skipped), then a schemaref child
	 *   video → the next segment must be `poster`, always an image, configured by
	 *           the video's own `settings.poster`; no sub-schema is consulted
	 *
	 * So `poster` under a video, `image` under a card, `item/image` under a deck,
	 * and `promo/poster` under a card (or `item/promo/poster` under a deck)
	 * holding a video all resolve here. Card-in-card is not walked (a card's
	 * schemaref child that is itself a card has no second schemaref hop).
	 *
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private function walkNestedPath(string $collection, string $property, string $objectId, string $parentType, string $subpath): array
	{
		$segments       = explode('/', $subpath);
		$parentSettings = $this->metaResolver->resolveSettings($collection, $property, $objectId);
		$type           = $parentType;
		$settings       = $parentSettings;

		if ($type === 'deck') {
			array_shift($segments); // the item id
		}

		foreach ($segments as $segment) {
			if ($type === 'video') {
				if ($segment !== 'poster') {
					throw new \UnexpectedValueException('Unknown saver service type for object.');
				}
				$posterSettings = $settings['poster'] ?? [];
				$type           = 'image';
				$settings       = is_array($posterSettings) ? $posterSettings : [];
				continue;
			}

			if ($type !== 'card' && $type !== 'deck') {
				throw new \UnexpectedValueException('Unknown saver service type for object.');
			}

			$childMeta = $this->metaResolver->resolveNested($collection, $property, $segment);
			$type      = PropertyDefinition::fromArray($childMeta)->resolveType();
			$settings  = is_array($childMeta['settings'] ?? null) ? $childMeta['settings'] : [];
		}

		return [$type, $settings];
	}
}
