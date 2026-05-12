<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;

readonly class ObjectPatcher
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
	) {
	}

	/**
	 * @param array<string,mixed> $newData
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function patchObject(string $collection, string $id, array $newData, bool $silent = false): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$mergedObject = array_merge($object->toArray(), $newData);

		return $this->objectUpdater->updateObject($collection, $id, $mergedObject, $silent);
	}

	/** @param array<string,mixed> $newData */
	public function patchObjectProperty(string $collection, string $id, string $property, array $newData): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$objectData            = $object->toArray();
		$objectData[$property] = array_merge($objectData[$property], $newData);

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}

	/**
	 * Patch a property nested inside another property:
	 *   - Card child: `obj[$parent][$path]` where `$path` is a single segment.
	 *   - Deck child: `obj[$parent][$itemId][$child]` where `$path` is `"itemId/child"`.
	 *
	 * `$path` is a slash-separated subpath; this method walks it from `$parent`
	 * down to the leaf, creating intermediate associative slots as needed, and
	 * `array_merge`s `$newData` into the leaf so siblings are preserved at every
	 * level.
	 *
	 * @param array<string,mixed> $newData
	 */
	public function patchNestedProperty(
		string $collection,
		string $id,
		string $parent,
		string $path,
		array $newData,
	): ObjectData {
		$object     = $this->objectFetcher->fetchObject($collection, $id);
		$objectData = $object->toArray();

		$segments = $path === '' ? [] : explode('/', $path);
		// Walk to (but not including) the leaf, creating slots as we go. Then
		// merge into the leaf so partial updates preserve sibling fields.
		$cursor =&$objectData[$parent];
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

		$existing      = isset($cursor[$leaf]) && is_array($cursor[$leaf]) ? $cursor[$leaf] : [];
		$cursor[$leaf] = array_merge($existing, $newData);

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}

	/**
	 * @param array<string,mixed> $newData
	 */
	public function patchObjectPropertyMeta(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $newData,
		?string $subpath = null,
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$propertyData = $object->properties->get($property);

		if (!$propertyData instanceof PropertyData) {
			throw new \UnexpectedValueException('Unable to locate object property to patch');
		}

		$patch = $propertyData->transform();

		if ($propertyData instanceof DepotData) {
			// Update a depot because they are different - **this mutates $property**
			$depotUpdater = new DepotPropertyManager($propertyData);
			$depotUpdater->patchMeta($name, $newData, $subpath);

			$patch = $propertyData->transform();
		} else {
			// Update a normal property
			foreach ($patch as $index => $child) {
				if ($child['name'] === $name) {
					$patch[$index] = array_merge($child, $newData);
					break;
				}
			}
		}

		$objectData            = $object->toArray();
		$objectData[$property] = $patch;

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}
}
