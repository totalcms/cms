<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Applies the "binaries never travel" rule to media fields inside cards and
 * decks.
 *
 * JumpStartExporter and JumpStartImporter each handle an object's top-level
 * media fields themselves; this carries the same treatment down into composite
 * fields, which neither used to look inside. A Site Builder page is the case
 * that matters most: its only image is `seo.image`, in the `seo` card, so a
 * page push sent a reference to a file the destination did not have, and the
 * next push overwrote whatever image the destination had uploaded instead.
 *
 * The treatment is deliberately identical to the top level: an image,
 * gallery, file or depot field is omitted from the payload, and the
 * destination's own value is carried forward on upsert when the payload does
 * not mention it.
 */
final readonly class NestedMediaFields
{
	/** Field types whose value points at a binary. They never travel; the destination owns them. */
	public const TYPES = ['image', 'gallery', 'file', 'depot'];

	/** Composites nest (a deck item can hold a card); stop well before a cyclic schemaref could loop. */
	private const MAX_DEPTH = 4;

	public function __construct(
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Strip media from inside the object's cards and decks. Top-level fields
	 * are left alone — the exporter has already dealt with those.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function strip(SchemaData $schema, array $data): array
	{
		return $this->walkComposites(
			$schema,
			$data,
			[],
			fn (SchemaData $child, array $value, array $existing, int $depth): array => $this->stripAll($child, $value, $depth),
			1,
		);
	}

	/**
	 * Carry the destination's media values inside cards and decks
	 * through an upsert, for every nested media key the payload omits. Deck
	 * items are matched by id; an item new to the destination has nothing to
	 * keep.
	 *
	 * @param array<string,mixed> $incoming
	 * @param array<string,mixed> $existing
	 *
	 * @return array<string,mixed>
	 */
	public function preserve(SchemaData $schema, array $incoming, array $existing): array
	{
		return $this->walkComposites($schema, $incoming, $existing, $this->preserveAll(...), 1);
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function stripAll(SchemaData $schema, array $data, int $depth): array
	{
		foreach ($schema->properties as $name => $property) {
			if (!isset($data[$name])) {
				continue;
			}

			if (in_array($this->fieldType($property), self::TYPES, true)) {
				unset($data[$name]);
			}
		}

		return $this->walkComposites(
			$schema,
			$data,
			[],
			fn (SchemaData $child, array $value, array $existing, int $next): array => $this->stripAll($child, $value, $next),
			$depth + 1,
		);
	}

	/**
	 * @param array<string,mixed> $incoming
	 * @param array<string,mixed> $existing
	 *
	 * @return array<string,mixed>
	 */
	private function preserveAll(SchemaData $schema, array $incoming, array $existing, int $depth): array
	{
		foreach ($schema->properties as $name => $property) {
			if (!in_array($this->fieldType($property), self::TYPES, true)) {
				continue;
			}

			// Present means authored (a factory rule in a starter kit), and
			// is honored — same as at the top level.
			if (!array_key_exists($name, $incoming) && array_key_exists($name, $existing)) {
				$incoming[$name] = $existing[$name];
			}
		}

		return $this->walkComposites($schema, $incoming, $existing, $this->preserveAll(...), $depth + 1);
	}

	/**
	 * Run $apply over each card value and each deck item in $data, handing it
	 * the child schema and the matching slice of $existing.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $existing
	 * @param \Closure(SchemaData, array<string,mixed>, array<string,mixed>, int): array<string,mixed> $apply
	 *
	 * @return array<string,mixed>
	 */
	private function walkComposites(SchemaData $schema, array $data, array $existing, \Closure $apply, int $depth): array
	{
		if ($depth > self::MAX_DEPTH) {
			return $data;
		}

		foreach ($schema->properties as $name => $property) {
			$type = $this->fieldType($property);

			if (!in_array($type, ['card', 'deck'], true) || !isset($data[$name]) || !is_array($data[$name])) {
				continue;
			}

			$child = $this->childSchema($property);
			if (!$child instanceof SchemaData) {
				continue;
			}

			$existingValue = is_array($existing[$name] ?? null) ? $existing[$name] : [];

			if ($type === 'card') {
				$data[$name] = $apply($child, $data[$name], $existingValue, $depth);
				continue;
			}

			foreach ($data[$name] as $itemId => $item) {
				if (!is_array($item)) {
					continue;
				}

				$existingItem          = is_array($existingValue[$itemId] ?? null) ? $existingValue[$itemId] : [];
				$data[$name][$itemId]  = $apply($child, $item, $existingItem, $depth);
			}
		}

		return $data;
	}

	/** @param array<string,mixed> $property */
	private function fieldType(array $property): string
	{
		return (string)($property['field'] ?? $property['type'] ?? '');
	}

	/** @param array<string,mixed> $property */
	private function childSchema(array $property): ?SchemaData
	{
		$ref = PropertyDefinition::extractSchemaRef($property);
		if ($ref === null) {
			return null;
		}

		try {
			return $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($ref));
		} catch (\Throwable) {
			// A card pointing at a schema that no longer exists has no media
			// we can identify. Leave it as it is rather than fail the sync.
			return null;
		}
	}
}
