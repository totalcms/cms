<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Schema\Data\PropertyDefinition;

/**
 * Which schema properties can hold an owner, and in what shape. The one rule
 * shared by the ownerProperty picker (FormOptionSources), the save-time check
 * (CollectionSaver) and the owner stamp (AccessGuard), so the picker never
 * offers a property the saver rejects or the stamp would corrupt.
 */
final class OwnerPropertyKind
{
	public const SCALAR = 'scalar';
	public const LIST   = 'list';

	/**
	 * How a schema property may hold an owner: SCALAR for a string property
	 * (declared `string`, or no declared type and a string-valued ref), LIST for
	 * a list of strings (`list`, or an inline `array` whose items are strings or
	 * untyped), null when the property cannot hold user ids (booleans, numbers,
	 * objects, and arrays of objects such as a gallery).
	 *
	 * @param array<string,mixed> $definition
	 */
	public static function of(array $definition): ?string
	{
		$property = PropertyDefinition::fromArray($definition);
		$type     = $property->effectiveJsonType();

		if ($type === null || $type === 'string') {
			return self::SCALAR;
		}

		if ($type !== 'array') {
			return null;
		}

		$resolved = $property->resolveType();
		if ($resolved === 'list') {
			return self::LIST;
		}

		// An inline array (no named property type such as gallery behind it):
		// a list owner only when its items are strings or untyped. The items
		// type is normalised like a property type, so a type list
		// (["string", "null"]) reads as its first non-null entry.
		if ($resolved !== 'array') {
			return null;
		}

		$items = $definition['items'] ?? [];
		if (!is_array($items)) {
			return self::LIST;
		}

		/** @var array<string,mixed> $items */
		$itemType = PropertyDefinition::fromArray($items)->type;

		return $itemType === null || $itemType === 'string' ? self::LIST : null;
	}
}
