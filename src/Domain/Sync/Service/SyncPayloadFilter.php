<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Sync\Data\SyncableCollections;

/**
 * Shapes a JumpStart payload for sync: narrows it to the schemas, templates,
 * objects and collection settings the operator selected, drops what must not
 * travel, counts what is left, and splits a seeding push in two.
 */
final class SyncPayloadFilter
{
	/**
	 * Filter a payload by schema ids, template ids, a per-collection
	 * object-id map (`collection => [ids] | null` for all), and the
	 * collection SETTINGS to include.
	 *
	 * @param array<string,mixed>                  $payload
	 * @param list<string>|null                    $schemaFilter
	 * @param list<string>|null                    $templateFilter
	 * @param array<string,list<string>|null>|null $collectionsFilter
	 * @param list<string>|null                    $collectionMetaFilter
	 *
	 * @return array<string,mixed>
	 */
	public function apply(array $payload, ?array $schemaFilter, ?array $templateFilter, ?array $collectionsFilter = null, ?array $collectionMetaFilter = null): array
	{
		if ($schemaFilter !== null && isset($payload['schemas']) && is_array($payload['schemas'])) {
			$payload['schemas'] = array_values(array_filter(
				$payload['schemas'],
				fn (array $s): bool => in_array($s['id'] ?? '', $schemaFilter, true)
			));
		}
		if ($templateFilter !== null && isset($payload['templates']) && is_array($payload['templates'])) {
			$payload['templates'] = array_values(array_filter(
				$payload['templates'],
				fn (array $t): bool => in_array($t['id'] ?? '', $templateFilter, true)
			));
		}
		if ($collectionsFilter !== null && isset($payload['objects']) && is_array($payload['objects'])) {
			$payload['objects'] = array_values(array_filter(
				$payload['objects'],
				function (array $o) use ($collectionsFilter): bool {
					$cid = (string)($o['collection'] ?? '');
					if (!array_key_exists($cid, $collectionsFilter)) {
						return false;
					}
					$ids = $collectionsFilter[$cid];

					return $ids === null || in_array((string)($o['id'] ?? ''), $ids, true);
				}
			));
		}

		// Unconditional, and deliberately outside the filter block: a remote
		// on an older release still exports the Twig Playground's collection
		// (see JumpStartExporter::exportSyncCollectionMeta for why it no
		// longer travels). Dropping it on arrival keeps a version-mismatched
		// remote from reporting a phantom "only on production" in the diff,
		// or creating the scratchpad collection here on pull.
		$payload = $this->stripCollectionMeta($payload, PlaygroundData::COLLECTION_ID);

		if ($collectionMetaFilter !== null) {
			return $this->filterCollectionMeta($payload, fn (string $id): bool => in_array($id, $collectionMetaFilter, true));
		}

		return $payload;
	}

	/**
	 * Remove one collection id from both arms of a payload's `collections`
	 * block, whatever entry shape it uses.
	 *
	 * @param array<string,mixed> $payload
	 *
	 * @return array<string,mixed>
	 */
	public function stripCollectionMeta(array $payload, string $id): array
	{
		return $this->filterCollectionMeta($payload, static fn (string $entryId): bool => $entryId !== $id);
	}

	/**
	 * Partition one export into the upsert payload and the seed-only payload.
	 *
	 * The split is exact rather than heuristic: `--objects` can only reach
	 * collections SyncableCollections::seedable() allows, and that excludes
	 * every collection the mirror path can carry objects for, so no object
	 * can belong to both halves. Only objects move to the seed payload —
	 * schemas, templates and collection settings stay on the mirror leg.
	 *
	 * @param array<string,list<string>|null> $seedFilter
	 *
	 * @return array{0: JumpStartData, 1: JumpStartData}
	 */
	public function splitSeeded(JumpStartData $jumpstart, array $seedFilter): array
	{
		$mirrorObjects = [];
		$seedObjects   = [];

		foreach ($jumpstart->objects as $object) {
			$collection = (string)($object['collection'] ?? '');
			if ($collection !== '' && array_key_exists($collection, $seedFilter) && SyncableCollections::seedable($collection)) {
				$seedObjects[] = $object;
				continue;
			}
			$mirrorObjects[] = $object;
		}

		// Clone rather than construct: JumpStartData's constructor stamps the
		// description with a timestamp, so a fresh instance would carry
		// different metadata than the export the operator is pushing.
		$mirror          = clone $jumpstart;
		$mirror->objects = $mirrorObjects;

		$seed              = clone $jumpstart;
		$seed->schemas     = [];
		$seed->templates   = [];
		$seed->factory     = [];
		$seed->collections = ['reserved' => [], 'custom' => []];
		$seed->objects     = $seedObjects;

		return [$mirror, $seed];
	}

	/**
	 * Count both arms of a `collections` block. Collections are stored as
	 * `['reserved' => [...], 'custom' => [...]]`, so a plain count() over the
	 * wrapper is always 2, and counting `objects` instead reported 0 for a
	 * settings-only sync — the exact case that looks like a silent no-op.
	 *
	 * @param array<string,mixed> $collections
	 */
	public static function countCollections(array $collections): int
	{
		$reserved = is_array($collections['reserved'] ?? null) ? $collections['reserved'] : [];
		$custom   = is_array($collections['custom'] ?? null) ? $collections['custom'] : [];

		return count($reserved) + count($custom);
	}

	/**
	 * Keep the collection entries in both arms whose id passes `$keep`.
	 *
	 * @param array<string,mixed>    $payload
	 * @param callable(string): bool $keep
	 *
	 * @return array<string,mixed>
	 */
	private function filterCollectionMeta(array $payload, callable $keep): array
	{
		if (!isset($payload['collections']) || !is_array($payload['collections'])) {
			return $payload;
		}

		foreach (['custom', 'reserved'] as $kind) {
			if (!isset($payload['collections'][$kind]) || !is_array($payload['collections'][$kind])) {
				continue;
			}
			$payload['collections'][$kind] = array_values(array_filter(
				$payload['collections'][$kind],
				static fn (mixed $entry): bool => $keep(self::collectionEntryId($entry))
			));
		}

		return $payload;
	}

	/** A collections-block entry is either a bare id string (reserved defaults) or a settings array keyed by `id`. */
	private static function collectionEntryId(mixed $entry): string
	{
		if (is_string($entry)) {
			return $entry;
		}

		return is_array($entry) ? (string)($entry['id'] ?? '') : '';
	}
}
