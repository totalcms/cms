<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

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
	) {
	}

	/**
	 * Build the collection-visualizer page data.
	 *
	 * @param array<\TotalCMS\Domain\Collection\Data\CollectionData> $allCollections
	 * @param array<string,string>                                    $query
	 *
	 * @return array<string,mixed>
	 */
	public function collectionGraph(array $allCollections, array $query): array
	{
		$graph        = $this->relationshipAnalyzer->analyze();
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
	 * @param array<\TotalCMS\Domain\Collection\Data\CollectionData> $allCollections
	 * @param array<string,string>                                    $query
	 *
	 * @return array<string,mixed>
	 */
	public function objectGraph(array $allCollections, array $query): array
	{
		$ovCollection = trim((string)($query['collection'] ?? ''));
		$ovId         = trim((string)($query['id'] ?? ''));
		$mermaid      = null;
		$nodeCount    = 0;
		$edgeCount    = 0;
		$truncated    = false;
		$mode         = '';

		if ($ovCollection !== '') {
			$graph = $ovId !== ''
				? $this->objectRelationshipResolver->resolve($ovCollection, $ovId)
				: $this->objectRelationshipResolver->resolveCollection($ovCollection);
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
}
