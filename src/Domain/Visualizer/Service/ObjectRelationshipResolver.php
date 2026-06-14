<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mcp\Service\ObjectTitleResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * Resolves a single object's actual relationships (not the schema's potential
 * ones) into an instance-level graph for the Object Visualizer.
 *
 *   - Outbound: the focal object's own relational property values point at other
 *     records.
 *   - Inbound: records elsewhere whose relational property points back at the
 *     focal id — the "what references this?" / safe-to-delete view. Bounded by
 *     the schema-derived relational map (so only the collections that *can*
 *     reference this one are scanned) and capped per source.
 */
readonly class ObjectRelationshipResolver
{
	/** Max inbound matches per referencing (collection, property) source. */
	private const CAP_PER_SOURCE = 50;

	/** Max focal objects rendered in collection mode (whole-collection view). */
	private const CAP_COLLECTION = 100;

	public function __construct(
		private RelationshipAnalyzer $analyzer,
		private ObjectFetcher $objectFetcher,
		private IndexReader $indexReader,
		private ObjectTitleResolver $titleResolver,
		private CollectionRepository $collections,
	) {
	}

	/**
	 * @return array{nodes: array<string,array<string,mixed>>, edges: list<array<string,mixed>>, truncated: bool}
	 */
	public function resolve(string $collection, string $id): array
	{
		$relations  = $this->analyzer->relationsFor($collection);
		$nodes      = [];
		$edges      = [];
		$truncated  = false;
		$indexCache = [];
		$titleProps = [];

		$titlePropFor = function (string $coll) use (&$titleProps): string {
			if (!array_key_exists($coll, $titleProps)) {
				$titleProps[$coll] = $this->titlePropertyFor($coll);
			}

			return $titleProps[$coll];
		};

		$labelFor = function (string $coll, string $objId) use (&$indexCache, $titlePropFor): string {
			if (!array_key_exists($coll, $indexCache)) {
				$indexCache[$coll] = $this->indexById($coll);
			}
			$entry = $indexCache[$coll][$objId] ?? null;

			return is_array($entry) ? $this->label($entry, $titlePropFor($coll)) : $objId;
		};

		$focalKey         = $this->nodeKey($collection, $id);
		$nodes[$focalKey] = $this->node($collection, $id, $labelFor($collection, $id), true);

		// Outbound — read the focal object's own relational values.
		if ($this->objectFetcher->existsObject($collection, $id)) {
			$data = $this->objectFetcher->fetchObject($collection, $id)->toArray();
			foreach ($relations['outbound'] as $rel) {
				if (str_starts_with($rel['target'], 'view:')) {
					continue; // view-target references aren't resolved to objects (v1)
				}
				foreach ($this->idsFromValue($data[$rel['property']] ?? null) as $refId) {
					$key           = $this->nodeKey($rel['target'], $refId);
					$nodes[$key] ??= $this->node($rel['target'], $refId, $labelFor($rel['target'], $refId), false);
					$edges[]       = ['from' => $focalKey, 'to' => $key, 'direction' => 'outbound', 'via' => $rel['property']];
				}
			}
		}

		// Inbound — records whose relational property points at this id.
		foreach ($relations['inbound'] as $rel) {
			[$matches, $more] = $this->referencingObjects($rel['collection'], $rel['property'], $id);
			$truncated        = $truncated || $more;
			foreach ($matches as $objId) {
				$key           = $this->nodeKey($rel['collection'], $objId);
				$nodes[$key] ??= $this->node($rel['collection'], $objId, $labelFor($rel['collection'], $objId), false);
				$edges[]       = ['from' => $key, 'to' => $focalKey, 'direction' => 'inbound', 'via' => $rel['property']];
			}
		}

		return ['nodes' => $nodes, 'edges' => $edges, 'truncated' => $truncated];
	}

	/**
	 * Whole-collection view: every object in $collection (capped) plus their
	 * immediate relational neighbours. Outbound edges are read from each object's
	 * indexed relational values; inbound edges come from scanning each referencing
	 * source collection once and keeping only the edges that land on a rendered
	 * focal object. Reads indexes only — no per-object file loads — so it stays
	 * bounded even for large collections.
	 *
	 * @return array{nodes: array<string,array<string,mixed>>, edges: list<array<string,mixed>>, truncated: bool}
	 */
	public function resolveCollection(string $collection): array
	{
		$relations  = $this->analyzer->relationsFor($collection);
		$nodes      = [];
		$edges      = [];
		$truncated  = false;
		$indexCache = [];
		$titleProps = [];

		$titlePropFor = function (string $coll) use (&$titleProps): string {
			if (!array_key_exists($coll, $titleProps)) {
				$titleProps[$coll] = $this->titlePropertyFor($coll);
			}

			return $titleProps[$coll];
		};

		$labelFor = function (string $coll, string $objId) use (&$indexCache, $titlePropFor): string {
			if (!array_key_exists($coll, $indexCache)) {
				$indexCache[$coll] = $this->indexById($coll);
			}
			$entry = $indexCache[$coll][$objId] ?? null;

			return is_array($entry) ? $this->label($entry, $titlePropFor($coll)) : $objId;
		};

		try {
			$index = $this->indexReader->fetchIndex($collection);
		} catch (\Throwable) {
			return ['nodes' => [], 'edges' => [], 'truncated' => false];
		}

		// Render the focal collection's own objects (capped) and their outbound
		// references. Track which ids made the cut so inbound edges only point at
		// objects we actually drew.
		$focalIds = [];
		foreach ($index->objects->all() as $entry) {
			$objId = (string)($entry['id'] ?? '');
			if ($objId === '') {
				continue;
			}
			if (count($focalIds) >= self::CAP_COLLECTION) {
				$truncated = true;
				break;
			}
			$focalIds[$objId] = true;

			$focalKey         = $this->nodeKey($collection, $objId);
			$nodes[$focalKey] = $this->node($collection, $objId, $this->label($entry, $titlePropFor($collection)), false);

			foreach ($relations['outbound'] as $rel) {
				if (str_starts_with($rel['target'], 'view:')) {
					continue;
				}
				foreach ($this->idsFromValue($entry[$rel['property']] ?? null) as $refId) {
					$key           = $this->nodeKey($rel['target'], $refId);
					$nodes[$key] ??= $this->node($rel['target'], $refId, $labelFor($rel['target'], $refId), false);
					$edges[]       = ['from' => $focalKey, 'to' => $key, 'direction' => 'outbound', 'via' => $rel['property']];
				}
			}
		}

		// Inbound — scan each referencing source once, draw edges that land on a
		// rendered focal object. Capped per source.
		foreach ($relations['inbound'] as $rel) {
			try {
				$srcIndex = $this->indexReader->fetchIndex($rel['collection']);
			} catch (\Throwable) {
				continue;
			}

			$count = 0;
			foreach ($srcIndex->objects->all() as $entry) {
				if ($count >= self::CAP_PER_SOURCE) {
					$truncated = true;
					break;
				}
				$srcId = (string)($entry['id'] ?? '');
				if ($srcId === '') {
					continue;
				}
				foreach ($this->idsFromValue($entry[$rel['property']] ?? null) as $refId) {
					if (!isset($focalIds[$refId])) {
						continue; // points at a focal object we didn't render (capped out)
					}
					$srcKey         = $this->nodeKey($rel['collection'], $srcId);
					$nodes[$srcKey] ??= $this->node($rel['collection'], $srcId, $this->label($entry, $titlePropFor($rel['collection'])), false);
					$edges[]        = ['from' => $srcKey, 'to' => $this->nodeKey($collection, $refId), 'direction' => 'inbound', 'via' => $rel['property']];
					$count++;
				}
			}
		}

		return ['nodes' => $nodes, 'edges' => $edges, 'truncated' => $truncated];
	}

	/**
	 * Ids of objects in $collection whose $property references $targetId, capped.
	 *
	 * @return array{0: list<string>, 1: bool} matched ids + whether more were capped
	 */
	private function referencingObjects(string $collection, string $property, string $targetId): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collection);
		} catch (\Throwable) {
			return [[], false];
		}

		$matches = [];
		$more    = false;
		foreach ($index->objects->all() as $entry) {
			if (!$this->valueReferences($entry[$property] ?? null, $targetId)) {
				continue;
			}
			$objId = (string)($entry['id'] ?? '');
			if ($objId === '') {
				continue;
			}
			if (count($matches) >= self::CAP_PER_SOURCE) {
				$more = true;
				break;
			}
			$matches[] = $objId;
		}

		return [$matches, $more];
	}

	private function valueReferences(mixed $value, string $targetId): bool
	{
		if (is_array($value)) {
			foreach ($value as $item) {
				if ((string)$item === $targetId) {
					return true;
				}
			}

			return false;
		}

		return $value !== null && (string)$value === $targetId;
	}

	/** @return list<string> */
	private function idsFromValue(mixed $value): array
	{
		if (is_array($value)) {
			$out = [];
			foreach ($value as $item) {
				$item = (string)$item;
				if ($item !== '') {
					$out[] = $item;
				}
			}

			return $out;
		}

		return is_string($value) && $value !== '' ? [$value] : [];
	}

	/** @return array<string,array<string,mixed>> id => index entry */
	private function indexById(string $collection): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collection);
		} catch (\Throwable) {
			return [];
		}

		$byId = [];
		foreach ($index->objects->all() as $entry) {
			if (isset($entry['id'])) {
				$byId[(string)$entry['id']] = $entry;
			}
		}

		return $byId;
	}

	/**
	 * Human label for an index entry, shared with the MCP layer so a collection's
	 * `mcp.titleProperty` (and the same convention fallbacks) drive both. When no
	 * title property is configured we first try a `first`/`last` person composite
	 * — a stronger label than a generic `name`, and something the single-property
	 * MCP resolver can't express — before delegating to {@see ObjectTitleResolver}
	 * (titleProperty → title/name/label/heading/headline → id).
	 *
	 * @param array<string,mixed> $entry
	 */
	private function label(array $entry, string $titleProperty): string
	{
		if ($titleProperty === '') {
			$first  = is_string($entry['first'] ?? null) ? trim((string)$entry['first']) : '';
			$last   = is_string($entry['last'] ?? null) ? trim((string)$entry['last']) : '';
			$person = trim($first . ' ' . $last);
			if ($person !== '') {
				return $person;
			}
		}

		return $this->titleResolver->resolve($entry, $titleProperty);
	}

	/** A collection's configured `mcp.titleProperty`, or '' when unset/unknown. */
	private function titlePropertyFor(string $collection): string
	{
		$data = $this->collections->fetchCollection($collection);
		$prop = $data?->mcp['titleProperty'] ?? null;

		return is_string($prop) ? $prop : '';
	}

	/** @return array<string,mixed> */
	private function node(string $collection, string $id, string $label, bool $focal): array
	{
		return [
			'id'         => $this->nodeKey($collection, $id),
			'collection' => $collection,
			'objectId'   => $id,
			'label'      => $label,
			'focal'      => $focal,
			'url'        => 'collections/' . $collection . '/' . $id,
		];
	}

	private function nodeKey(string $collection, string $id): string
	{
		return $collection . '::' . $id;
	}
}
