<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\AutogenService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Merge imported items into a deck property.
 *
 * The CSV and JSON deck importers differ only in how they turn a file into
 * an `id => item` dictionary; everything after that — fetching the parent,
 * checking the property is a deck, skipping existing items unless updating,
 * merging, writing the parent once under import suspension and firing
 * `import.updated` — lives here, as does the item-id rule.
 */
final readonly class DeckItemImporter
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private SchemaFetcher $schemaFetcher,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * @param array<string, array<string,mixed>> $items  id => item
	 * @param bool                               $update merge into items that already exist instead of skipping them
	 *
	 * @throws \InvalidArgumentException when $property is not a deck
	 */
	public function importItems(string $collection, string $objectId, string $property, array $items, bool $update, LoggerInterface $logger): int
	{
		$object       = $this->objectFetcher->fetchObject($collection, $objectId);
		$deckProperty = $object->properties->get($property);

		if (!$deckProperty instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$property}' is not a deck property");
		}

		$deck  = $deckProperty->deck;
		$count = 0;

		foreach ($items as $itemId => $item) {
			$itemId = (string)$itemId;
			$exists = isset($deck[$itemId]);

			if ($exists && !$update) {
				$logger->info("Skipping existing deck item: {$itemId}");
				continue;
			}

			$item['id'] = $itemId;

			if ($exists) {
				$deck[$itemId] = array_merge($deck[$itemId], $item);
				$logger->info("Updated deck item: {$itemId}");
			} else {
				$deck[$itemId] = $item;
				$logger->info("Imported deck item: {$itemId}");
			}

			$count++;
		}

		if ($count === 0) {
			return 0;
		}

		$objectData            = $object->toArray();
		$objectData[$property] = $deck;

		// One parent write. `object.updated` is suppressed and `import.updated`
		// fired instead, so listeners can tell a deck import from a user save.
		$this->eventDispatcher->suspendForImport($collection);
		try {
			$this->objectUpdater->updateObject($collection, $objectId, $objectData);
			$updated = $this->objectFetcher->fetchObject($collection, $objectId);
			$this->eventDispatcher->dispatch(CoreEvent::IMPORT_UPDATED, new ObjectEventPayload($collection, $objectId, $updated, $object));
		} finally {
			$this->eventDispatcher->resumeForImport($collection);
		}

		return $count;
	}

	/**
	 * The id for an imported item: its own `id` when it has one, else one
	 * generated from the deck schema's autogen pattern, else a uid.
	 *
	 * @param array<string,mixed> $item
	 */
	public function resolveItemId(array $item, string $autogenPattern): string
	{
		if (isset($item['id']) && trim((string)$item['id']) !== '') {
			return self::sanitizeId((string)$item['id']);
		}

		if ($autogenPattern !== '') {
			return self::sanitizeId(AutogenService::generateWithOidCount($autogenPattern, $item, 0));
		}

		return str_replace('-', '_', AutogenIdService::generateUid());
	}

	/**
	 * Deck item ids are slugs with underscores: `Hello World` → `hello_world`.
	 */
	public static function sanitizeId(string $id): string
	{
		return str_replace('-', '_', SlugData::slugify($id));
	}

	/**
	 * The `autogen` pattern on the deck item schema's id property, or '' when
	 * the deck has no item schema or no pattern.
	 */
	public function idAutogenPattern(string $collection, string $property): string
	{
		try {
			$schema         = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$propertyConfig = $schema->properties[$property] ?? null;
			if (!$propertyConfig) {
				return '';
			}

			$schemaref = PropertyDefinition::extractSchemaRef($propertyConfig);
			if ($schemaref === null) {
				return '';
			}

			return $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($schemaref))->properties['id']['settings']['autogen'] ?? '';
		} catch (\Exception) {
			return '';
		}
	}
}
