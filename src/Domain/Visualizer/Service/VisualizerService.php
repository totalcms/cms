<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Facade over the four visualizer services so AdminUtilsAction
 * only needs a single dep for both visualizer pages.
 */
readonly class VisualizerService
{
	public function __construct(
		private RelationshipAnalyzer $relationshipAnalyzer,
		private MermaidErdRenderer $mermaidErdRenderer,
		private ObjectRelationshipResolver $objectRelationshipResolver,
		private MermaidFlowchartRenderer $mermaidFlowchartRenderer,
		private AccessGroupAnalyzer $accessGroupAnalyzer,
		private AccessGroupMatrixPresenter $accessGroupMatrixPresenter,
		private AccessControlService $accessControl,
	) {
	}

	/**
	 * Build the collection-visualizer page data.
	 *
	 * @param array<CollectionData> $allCollections
	 * @param array<string,string>  $query
	 * @param string|null           $userId Current operator id; null or super-admin → no filtering.
	 *
	 * @return array<string,mixed>
	 */
	public function collectionGraph(array $allCollections, array $query, ?string $userId = null): array
	{
		$permitted    = $this->permittedCollectionIds($userId, $allCollections);
		$allCollections = $permitted !== null ? array_values(array_filter(
			$allCollections,
			static fn (CollectionData $c): bool => in_array($c->id, $permitted, true),
		)) : $allCollections;

		$graph        = $this->relationshipAnalyzer->analyze();
		$graph        = $this->filterGraph($graph, $permitted);
		$focus        = trim((string)($query['collection'] ?? ''));
		$showIsolated = ($query['isolated'] ?? '') === '1';
		$hiddenCount  = 0;

		if ($focus !== '' && isset($graph['nodes'][$focus])) {
			$graph = $this->relationshipAnalyzer->egoGraph($graph, $focus);
		} else {
			$focus = '';
			if (!$showIsolated) {
				$before      = count($graph['nodes']);
				$graph       = $this->relationshipAnalyzer->pruneIsolated($graph);
				$hiddenCount = $before - count($graph['nodes']);
			}
		}

		$mermaid = $this->mermaidErdRenderer->render($graph);

		return [
			'mermaid'      => $mermaid,
			'edgeTypes'    => $this->mermaidErdRenderer->edgeTypes(),
			'focus'        => $focus,
			'collections'  => $allCollections,
			'nodeCount'    => count($graph['nodes']),
			'edgeCount'    => count($graph['edges']),
			'showIsolated' => $showIsolated,
			'hiddenCount'  => $hiddenCount,
		];
	}

	/**
	 * Build the object-visualizer page data.
	 *
	 * @param array<CollectionData> $allCollections
	 * @param array<string,string>  $query
	 * @param string|null           $userId Current operator id; null or super-admin → no filtering.
	 *
	 * @return array<string,mixed>
	 */
	public function objectGraph(array $allCollections, array $query, ?string $userId = null): array
	{
		$permitted    = $this->permittedCollectionIds($userId, $allCollections);
		$allCollections = $permitted !== null ? array_values(array_filter(
			$allCollections,
			static fn (CollectionData $c): bool => in_array($c->id, $permitted, true),
		)) : $allCollections;

		$ovCollection = trim((string)($query['collection'] ?? ''));
		$ovId         = trim((string)($query['id'] ?? ''));
		$mermaid      = null;
		$nodeCount    = 0;
		$edgeCount    = 0;
		$truncated    = false;
		$mode         = '';

		// If the requested collection is not permitted, render nothing.
		if ($ovCollection !== '' && $permitted !== null && !in_array($ovCollection, $permitted, true)) {
			$ovCollection = '';
		}

		if ($ovCollection !== '') {
			$graph = $ovId !== ''
				? $this->objectRelationshipResolver->resolve($ovCollection, $ovId)
				: $this->objectRelationshipResolver->resolveCollection($ovCollection);

			// Drop nodes/edges referencing collections the user cannot read.
			if ($permitted !== null) {
				$graph = $this->filterObjectGraph($graph, $permitted);
			}

			$mode      = $ovId !== '' ? 'object' : 'collection';
			$mermaid   = $this->mermaidFlowchartRenderer->render($graph);
			$nodeCount = count($graph['nodes']);
			$edgeCount = count($graph['edges']);
			$truncated = $graph['truncated'];
		}

		return [
			'collection'  => $ovCollection,
			'id'          => $ovId,
			'mode'        => $mode,
			'collections' => $allCollections,
			'mermaid'     => $mermaid,
			'nodeCount'   => $nodeCount,
			'edgeCount'   => $edgeCount,
			'truncated'   => $truncated,
		];
	}

	/**
	 * Return the set of collection ids the user may read, or null when no
	 * filtering is needed (super-admin or no userId given).
	 *
	 * @param array<CollectionData> $allCollections
	 *
	 * @return list<string>|null
	 */
	private function permittedCollectionIds(?string $userId, array $allCollections): ?array
	{
		if ($userId === null || $userId === '' || $this->accessControl->isAdmin($userId)) {
			return null;
		}

		$permitted = [];
		foreach ($allCollections as $collection) {
			if ($this->accessControl->canAccessCollection($userId, $collection->id, 'read')) {
				$permitted[] = $collection->id;
			}
		}

		return $permitted;
	}

	/**
	 * Filter a collection-ERD graph to only include nodes whose collection id
	 * appears in $permitted (non-collection nodes such as schema: / view: / missing
	 * are kept only when they are still reachable via a kept edge).
	 *
	 * @param array{nodes: array<string,array<string,mixed>>, edges: list<array<string,mixed>>} $graph
	 * @param list<string>|null                                                                  $permitted null = no filtering
	 *
	 * @return array{nodes: array<string,array<string,mixed>>, edges: list<array<string,mixed>>}
	 */
	private function filterGraph(array $graph, ?array $permitted): array
	{
		if ($permitted === null) {
			return $graph;
		}

		// Keep collection nodes that are permitted; keep schema/view/missing nodes
		// only when they survive edge pruning below.
		$permittedSet = array_flip($permitted);

		$isPermittedNode = static function (string $id, array $node) use ($permittedSet): bool {
			$kind = (string)($node['kind'] ?? '');
			if ($kind === 'collection') {
				return isset($permittedSet[$id]);
			}

			// Non-collection nodes (schema:, view:, missing) are kept tentatively;
			// they will be dropped during edge pruning if no kept edge references them.
			return true;
		};

		// Prune edges where either endpoint is a non-permitted collection node.
		$keptEdges = [];
		foreach ($graph['edges'] as $edge) {
			$from     = (string)$edge['from'];
			$to       = (string)$edge['to'];
			$fromNode = $graph['nodes'][$from] ?? ['kind' => 'missing'];
			$toNode   = $graph['nodes'][$to] ?? ['kind' => 'missing'];

			if (!$isPermittedNode($from, $fromNode) || !$isPermittedNode($to, $toNode)) {
				continue;
			}

			$keptEdges[] = $edge;
		}

		// Build the reachable node set from kept edges + permitted collection nodes.
		$reachable = array_flip($permitted);
		foreach ($keptEdges as $edge) {
			$reachable[(string)$edge['from']] = true;
			$reachable[(string)$edge['to']]   = true;
		}

		$filteredNodes = [];
		foreach ($graph['nodes'] as $id => $node) {
			if (isset($reachable[$id])) {
				$filteredNodes[$id] = $node;
			}
		}

		return ['nodes' => $filteredNodes, 'edges' => $keptEdges];
	}

	/**
	 * Filter an object-graph to drop nodes/edges that reference collections the
	 * user cannot read. The focal object's node is always kept (its collection was
	 * already checked by objectGraph()). Only neighbour nodes whose collection is
	 * not in $permitted are removed, along with their edges.
	 *
	 * @param array{nodes: array<string,array<string,mixed>>, edges: list<array<string,mixed>>, truncated: bool} $graph
	 * @param list<string>                                                                                         $permitted
	 *
	 * @return array{nodes: array<string,array<string,mixed>>, edges: list<array<string,mixed>>, truncated: bool}
	 */
	private function filterObjectGraph(array $graph, array $permitted): array
	{
		$permittedSet = array_flip($permitted);

		// Determine which nodes to keep: keep if their collection is permitted.
		$keepNode = [];
		foreach ($graph['nodes'] as $id => $node) {
			$collection = (string)($node['collection'] ?? '');
			$keepNode[$id] = $collection === '' || isset($permittedSet[$collection]);
		}

		// Prune edges where either endpoint is dropped.
		$keptEdges = [];
		foreach ($graph['edges'] as $edge) {
			$from = (string)$edge['from'];
			$to   = (string)$edge['to'];
			if (($keepNode[$from] ?? false) && ($keepNode[$to] ?? false)) {
				$keptEdges[] = $edge;
			}
		}

		// Keep only nodes that are permitted AND still reachable or focal.
		$filteredNodes = [];
		foreach ($graph['nodes'] as $id => $node) {
			if ($keepNode[$id] ?? false) {
				$filteredNodes[$id] = $node;
			}
		}

		return ['nodes' => $filteredNodes, 'edges' => $keptEdges, 'truncated' => $graph['truncated']];
	}

	/**
	 * Build the Permission Matrix page data.
	 *
	 * @return array{
	 *   columnGroups: list<array{dimension: string, label: string, columns: list<array{id: string, label: string}>}>,
	 *   rows: list<array{id: string, name: string, isSuperAdmin: bool, cells: array<string, string>}>,
	 * }
	 */
	public function accessGroupMatrix(): array
	{
		$model = $this->accessGroupAnalyzer->analyze();

		return $this->accessGroupMatrixPresenter->present($model);
	}
}
