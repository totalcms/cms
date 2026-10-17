<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Auth\Service\ForcedValueContext;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\DateData;
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
		private ForcedValueContext $forcedValues,
	) {
	}

	/**
	 * @param ObjectData|array<string,mixed> $object
	 * @param bool                           $preserveDates Keep authored values in
	 *                                                      onUpdate/onCreate date fields
	 *                                                      instead of restamping. See
	 *                                                      ObjectSaver::saveObject() —
	 *                                                      same contract, used by the
	 *                                                      sync/upsert import so a synced
	 *                                                      copy never reads as newer than
	 *                                                      its source.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function updateObject(string $collection, string $id, ObjectData|array $object, bool $silent = false, bool $preserveDates = false): ObjectData
	{
		// Forced values from the field policy (protected: {value: X}),
		// remembered by the access middleware for this request's non-admin
		// write. Laid over every save so no write path can skip them.
		$forced = $this->forcedValues->forcedFor($collection, $id);
		if ($forced !== []) {
			$data   = $object instanceof ObjectData ? $object->toArray() : $object;
			$object = array_replace($data, $forced);
		}

		if (!$object instanceof ObjectData) {
			$object = $this->factory->generateObject($collection, $object);
		}

		if ($object->id !== $id) {
			throw new \UnexpectedValueException('Invalid Object data provided. Does not match object ID.', 1);
		}

		// Capture pre-save state for listeners that need to diff (e.g. nested
		// file orphan cleanup). Skipped on silent updates since they suppress
		// the event entirely. Failure here is non-fatal — `previous` is optional.
		$previous = null;
		if (!$silent) {
			try {
				$previous = $this->objectFetcher->fetchObject($collection, $id);
			} catch (\Throwable) {
				// First write or missing on disk — no previous state to diff against.
			}
		}

		// Run property actions before saving (ex: update date). In preserve
		// mode, a date field that already carries an authored value is left
		// untouched; everything else still runs through the processor.
		$object->properties = $object->properties->map(function (PropertyData $property) use ($preserveDates): PropertyData {
			if ($preserveDates && $property instanceof DateData && $property->hasAuthoredDate()) {
				return $property;
			}

			return $this->propertyProcessor->processBeforeSave($property);
		});

		$this->storage->saveObject($collection, $object);

		// Silent updates skip the object.updated cascade (collection metadata,
		// index rebuild, dataviews, cache invalidation). Use only for internal
		// bookkeeping writes where no listener legitimately needs to react —
		// e.g. recording a login timestamp.
		if (!$silent) {
			$this->eventDispatcher->dispatch(CoreEvent::OBJECT_UPDATED, new ObjectEventPayload($collection, $object->id, $object, $previous));
		}

		return $object;
	}

	/**
	 * @param array<string,mixed> $newData
	 * @param array<string,mixed> $extra   top-level fields written in the same save, e.g. forced values from AuthFieldPolicy
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function updateObjectProperty(string $collection, string $id, string $property, array $newData, bool $silent = false, array $extra = []): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		// Run property actions before saving (ex: update date)
		$object->properties = $object->properties->map(fn (PropertyData $property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$objectData            = $object->toArray();
		$objectData[$property] = $newData;
		$objectData            = array_replace($objectData, $extra);

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

		$slot = &NestedPath::slot($objectData, $parent, $path);
		$slot = $newData;

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
				return $property;
			}

			return $item;
		});

		return $this->updateObject($collection, $id, $object, $silent);
	}
}
