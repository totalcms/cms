<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Analyzes every collection's schema and emits a normalized relationship graph
 * shared by the data visualizer utilities. Four edge types, drawn from where
 * the metadata actually lives:
 *
 *   - relational   — `property.settings.relationalOptions.{collection|view}` (FK)
 *   - composition  — deck/card `schemaref` (embeds a sub-schema)
 *   - inheritance  — schema `inheritFrom[]` (extends a parent schema)
 *   - dataview     — a DataView's `dependencies` / `viewDependencies`
 *
 * Nodes are collections by default. Composition/inheritance targets are schemas;
 * when a schema is bound to one or more collections the edge is redirected onto
 * those collections, otherwise it points at a secondary `schema` node so the
 * edge is never silently dropped. DataViews are `view` nodes.
 *
 * The graph is structural only — no object data is read (the dataview deps come
 * from the small, already-indexed `dataviews` collection).
 */
readonly class RelationshipAnalyzer
{
	public function __construct(
		private CollectionLister $collectionLister,
		private SchemaFetcher $schemaFetcher,
		private DataViewLister $dataViewLister,
	) {
	}

	/**
	 * Build the full graph.
	 *
	 * @return array{nodes: array<string, array<string,mixed>>, edges: list<array<string,mixed>>}
	 */
	public function analyze(): array
	{
		$nodes = [];
		$edges = [];

		$collections = $this->collectionLister->listAllCollections();

		// Map schema id -> collection ids using it, so schema-level edges
		// (composition/inheritance) can be redirected onto real collections.
		$collectionsBySchema = [];
		foreach ($collections as $collection) {
			$collectionsBySchema[$collection->schema][] = $collection->id;
			$nodes[$collection->id]                     = [
				'id'     => $collection->id,
				'kind'   => 'collection',
				'label'  => $collection->name !== '' ? $collection->name : $collection->id,
				'schema' => $collection->schema,
				'fields' => [],
			];
		}

		foreach ($collections as $collection) {
			try {
				$schema = $this->schemaFetcher->fetchRawSchemaForCollection($collection->id);
			} catch (\Throwable) {
				continue; // collection without a resolvable schema — skip its edges
			}

			$nodes[$collection->id]['fields'] = $this->extractFields($schema);

			foreach ($schema->properties as $propName => $propDef) {
				if (!is_array($propDef)) {
					continue;
				}
				$propName = (string)$propName;

				// Relational (FK) — points at another collection or a view.
				$relational = $propDef['settings']['relationalOptions'] ?? null;
				if (is_array($relational)) {
					$view       = (string)($relational['view'] ?? '');
					$collTarget = (string)($relational['collection'] ?? '');
					if ($view !== '') {
						$edges[] = ['from' => $collection->id, 'to' => 'view:' . $view, 'type' => 'relational', 'via' => $propName];
					} elseif ($collTarget !== '') {
						$edges[] = ['from' => $collection->id, 'to' => $collTarget, 'type' => 'relational', 'via' => $propName];
					}
				}

				// Composition — deck/card properties referencing a sub-schema.
				$ref = $this->schemaRef($propDef);
				if ($ref !== '') {
					foreach ($this->resolveSchemaTargets($ref, $collectionsBySchema) as $target) {
						if ($target === $collection->id) {
							continue; // self-reference adds no information
						}
						$edges[] = ['from' => $collection->id, 'to' => $target, 'type' => 'composition', 'via' => $propName];
					}
				}
			}

			// Inheritance — schema extends parent schema(s).
			foreach ($schema->inheritFrom as $parent) {
				$parent = (string)$parent;
				if ($parent === '') {
					continue;
				}
				foreach ($this->resolveSchemaTargets($parent, $collectionsBySchema) as $target) {
					if ($target === $collection->id) {
						continue;
					}
					$edges[] = ['from' => $collection->id, 'to' => $target, 'type' => 'inheritance', 'via' => ''];
				}
			}
		}

		// DataView dependency edges (deps are indexed on the dataviews collection).
		// Best-effort: these are supplementary, so a missing/unbuildable dataviews
		// collection must not sink the whole graph.
		try {
			$views = $this->dataViewLister->listViews();
		} catch (\Throwable) {
			$views = [];
		}
		foreach ($views as $view) {
			if (!is_array($view)) {
				continue;
			}
			$viewId = (string)($view['id'] ?? '');
			if ($viewId === '') {
				continue;
			}
			$viewNode          = 'view:' . $viewId;
			$nodes[$viewNode]  = [
				'id'     => $viewNode,
				'kind'   => 'view',
				'label'  => ((string)($view['name'] ?? '')) !== '' ? (string)$view['name'] : $viewId,
				'schema' => 'dataview',
				'fields' => [],
			];

			foreach ($this->toList($view['dependencies'] ?? []) as $dep) {
				$edges[] = ['from' => $viewNode, 'to' => $dep, 'type' => 'dataview', 'via' => ''];
			}
			foreach ($this->toList($view['viewDependencies'] ?? []) as $dep) {
				$edges[] = ['from' => $viewNode, 'to' => 'view:' . $dep, 'type' => 'dataview', 'via' => ''];
			}
		}

		// Ensure every edge endpoint has a node — referenced schemas with no
		// collection become `schema` nodes; dangling refs become `missing`.
		foreach ($edges as $edge) {
			foreach ([$edge['from'], $edge['to']] as $endpoint) {
				if (isset($nodes[$endpoint])) {
					continue;
				}
				$nodes[$endpoint] = $this->stubNode($endpoint);
			}
		}

		return ['nodes' => $nodes, 'edges' => $edges];
	}

	/**
	 * Reduce the graph to a single collection and its immediate neighbours
	 * (1-hop ego graph) for the filtered view.
	 *
	 * @param array{nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>} $graph
	 *
	 * @return array{nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>}
	 */
	public function egoGraph(array $graph, string $focusId): array
	{
		if (!isset($graph['nodes'][$focusId])) {
			return ['nodes' => [], 'edges' => []];
		}

		$keep  = [$focusId => true];
		$edges = [];
		foreach ($graph['edges'] as $edge) {
			if ($edge['from'] === $focusId || $edge['to'] === $focusId) {
				$edges[]              = $edge;
				$keep[$edge['from']]  = true;
				$keep[$edge['to']]    = true;
			}
		}

		$nodes = [];
		foreach ($keep as $id => $_) {
			if (isset($graph['nodes'][$id])) {
				$nodes[$id] = $graph['nodes'][$id];
			}
		}

		return ['nodes' => $nodes, 'edges' => $edges];
	}

	/**
	 * Drop nodes that take part in no relationship. On the global view these
	 * unconnected collections only add horizontal width without conveying any
	 * relationship, so they're hidden by default.
	 *
	 * @param array{nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>} $graph
	 *
	 * @return array{nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>}
	 */
	public function pruneIsolated(array $graph): array
	{
		$connected = [];
		foreach ($graph['edges'] as $edge) {
			$connected[$edge['from']] = true;
			$connected[$edge['to']]   = true;
		}

		$nodes = [];
		foreach ($graph['nodes'] as $id => $node) {
			if (isset($connected[$id])) {
				$nodes[$id] = $node;
			}
		}

		return ['nodes' => $nodes, 'edges' => $graph['edges']];
	}

	/**
	 * Inbound + outbound relational references for one collection, derived from
	 * the relational (FK) edges. Powers the Object Visualizer: outbound tells it
	 * which of a record's properties point elsewhere; inbound is the bounded set
	 * of (collection, property) pairs to reverse-look-up for "what references me".
	 *
	 * @return array{
	 *     inbound: list<array{collection:string,property:string}>,
	 *     outbound: list<array{property:string,target:string}>
	 * }
	 */
	public function relationsFor(string $collection): array
	{
		$inbound  = [];
		$outbound = [];

		foreach ($this->analyze()['edges'] as $edge) {
			if (($edge['type'] ?? '') !== 'relational') {
				continue;
			}
			if ($edge['to'] === $collection) {
				$inbound[] = ['collection' => (string)$edge['from'], 'property' => (string)$edge['via']];
			}
			if ($edge['from'] === $collection) {
				$outbound[] = ['property' => (string)$edge['via'], 'target' => (string)$edge['to']];
			}
		}

		return ['inbound' => $inbound, 'outbound' => $outbound];
	}

	/**
	 * Resolve a schema reference (a `$id` URL or a bare schema id) to the
	 * collection ids that use it, or to a `schema:` node id when no collection
	 * is bound to it.
	 *
	 * @param array<string,list<string>> $collectionsBySchema
	 *
	 * @return list<string>
	 */
	private function resolveSchemaTargets(string $ref, array $collectionsBySchema): array
	{
		$schemaId = $this->schemaIdFromRef($ref);
		if ($schemaId === '') {
			return [];
		}

		if (($collectionsBySchema[$schemaId] ?? []) !== []) {
			return $collectionsBySchema[$schemaId];
		}

		return ['schema:' . $schemaId];
	}

	/**
	 * Extract the deck/card schema reference from a property definition.
	 *
	 * @param array<string,mixed> $propDef
	 */
	private function schemaRef(array $propDef): string
	{
		$ref = $propDef['schemaref']
			?? $propDef['deckref']
			?? ($propDef['settings']['schemaref'] ?? ($propDef['settings']['deckref'] ?? ''));

		return is_string($ref) ? $ref : '';
	}

	/** A `$id` URL like `https://…/schemas/foo.json` resolves to `foo`. */
	private function schemaIdFromRef(string $ref): string
	{
		if (str_contains($ref, '/')) {
			return basename($ref, '.json');
		}

		return basename($ref, '.json');
	}

	/**
	 * Build the display field list for an entity from its schema properties.
	 *
	 * @return list<array{name:string,type:string}>
	 */
	private function extractFields(SchemaData $schema): array
	{
		$fields = [];
		foreach ($schema->properties as $name => $def) {
			if (!is_array($def)) {
				continue;
			}
			$fields[] = ['name' => (string)$name, 'type' => $this->fieldType($def)];
		}

		return $fields;
	}

	/**
	 * Short type token for a property (for the ERD field rows).
	 *
	 * @param array<string,mixed> $def
	 */
	private function fieldType(array $def): string
	{
		if (isset($def['type']) && is_string($def['type']) && $def['type'] !== '') {
			return $def['type'];
		}
		if (isset($def['$ref']) && is_string($def['$ref'])) {
			return basename($def['$ref'], '.json');
		}
		if (isset($def['field']) && is_string($def['field'])) {
			return $def['field'];
		}

		return 'string';
	}

	/** @return array<string,mixed> */
	private function stubNode(string $id): array
	{
		if (str_starts_with($id, 'view:')) {
			return ['id' => $id, 'kind' => 'view', 'label' => substr($id, 5), 'schema' => 'dataview', 'fields' => []];
		}
		if (str_starts_with($id, 'schema:')) {
			return ['id' => $id, 'kind' => 'schema', 'label' => substr($id, 7), 'schema' => substr($id, 7), 'fields' => []];
		}

		// An edge referencing a collection id that doesn't exist on this site.
		return ['id' => $id, 'kind' => 'missing', 'label' => $id, 'schema' => '', 'fields' => []];
	}

	/**
	 * @param mixed $value
	 *
	 * @return list<string>
	 */
	private function toList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			$item = (string)$item;
			if ($item !== '') {
				$out[] = $item;
			}
		}

		return $out;
	}
}
