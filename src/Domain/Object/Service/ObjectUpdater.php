<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;

readonly class ObjectUpdater
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectRepository $storage,
		private ObjectFactory $factory,
		private PropertyDataProcessorInterface $propertyProcessor,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * @param ObjectData|array<string,mixed> $object
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function updateObject(string $collection, string $id, ObjectData|array $object, bool $silent = false): ObjectData
	{
		if (!$object instanceof ObjectData) {
			$object = $this->factory->generateObject($collection, $object);
		}

		if ($object->id !== $id) {
			throw new \UnexpectedValueException('Invalid Object data provided. Does not match object ID.', 1);
		}

		// Run property actions before saving (ex: update date)
		$object->properties = $object->properties->map(fn (PropertyData $property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$this->storage->saveObject($collection, $object);

		// Silent updates skip the object.updated cascade (collection metadata,
		// index rebuild, dataviews, cache invalidation). Use only for internal
		// bookkeeping writes where no listener legitimately needs to react —
		// e.g. recording a login timestamp.
		if (!$silent) {
			$this->eventDispatcher->dispatch('object.updated', new ObjectEventPayload($collection, $object->id, $object));
		}

		return $object;
	}

	/**
	 * @param array<string,mixed> $newData
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function updateObjectProperty(string $collection, string $id, string $property, array $newData, bool $silent = false): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		// Run property actions before saving (ex: update date)
		$object->properties = $object->properties->map(fn (PropertyData $property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$objectData            = $object->toArray();
		$objectData[$property] = $newData;

		return $this->updateObject($collection, $id, $objectData, $silent);
	}

	/**
	 * Replace a property nested inside another property:
	 *   - Card child: `obj[$parent][$path]` where `$path` is a single segment.
	 *   - Deck child: `obj[$parent][$itemId][$child]` where `$path` is `"itemId/child"`.
	 *
	 * `$path` is a slash-separated subpath; siblings at every level are preserved
	 * (only the leaf is replaced).
	 *
	 * @param array<string,mixed> $newData
	 */
	public function updateNestedProperty(string $collection, string $id, string $parent, string $path, array $newData): ObjectData
	{
		$object     = $this->objectFetcher->fetchObject($collection, $id);
		$objectData = $object->toArray();

		$segments = $path === '' ? [] : explode('/', $path);
		$cursor   =&$objectData[$parent];
		if (!is_array($cursor)) {
			$cursor = [];
		}
		$leaf = (string)array_pop($segments);
		foreach ($segments as $segment) {
			if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
				$cursor[$segment] = [];
			}
			$cursor =&$cursor[$segment];
		}

		$cursor[$leaf] = $newData;

		return $this->updateObject($collection, $id, $objectData);
	}

	/**
	 * @param array<string,mixed> $newData
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function updateObjectPropertyMeta(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $newData,
		?string $subpath = null,
		bool $silent = false,
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$property = $object->properties->get($property);

		if (!$property instanceof PropertyData) {
			throw new \UnexpectedValueException('Unable to locate object property to update');
		}

		if ($property instanceof DepotData) {
			// Update a depot because they are different - **this mutates $property**
			$depotUpdater = new DepotPropertyManager($property);
			// There is no "update" meta API, so we just merge with existing data
			$depotUpdater->patchMeta($name, $newData, $subpath);
		}

		$object->properties = $object->properties->map(function ($item) use ($property): PropertyData {
			if ($item->id === $property->id) {
				$item = $property;
			}

			return $item;
		});

		return $this->updateObject($collection, $id, $object, $silent);
	}
}
