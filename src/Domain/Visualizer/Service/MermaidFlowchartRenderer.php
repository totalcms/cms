<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

/**
 * Renders an {@see ObjectRelationshipResolver} instance graph as a Mermaid
 * `flowchart`: the focal record, its referenced/referencing objects grouped
 * into a subgraph per collection, and arrows whose direction shows inbound vs
 * outbound (into the focal node = referenced-by, out = references).
 *
 * Node ids are sanitized to Mermaid-safe tokens; labels are quoted.
 */
final class MermaidFlowchartRenderer
{
	/** @var array<string,string> node key => mermaid node id */
	private array $nodeIds = [];

	/** @var array<string,bool> taken mermaid ids (collision guard) */
	private array $taken = [];

	/**
	 * @param array{nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>} $graph
	 */
	public function render(array $graph): string
	{
		$this->nodeIds = [];
		$this->taken   = [];

		$lines = ['flowchart LR'];

		// Group nodes into a subgraph per collection.
		$byCollection = [];
		foreach ($graph['nodes'] as $node) {
			$byCollection[(string)$node['collection']][] = $node;
		}

		foreach ($byCollection as $collection => $nodes) {
			$lines[] = sprintf('    subgraph %s["%s"]', $this->subgraphId((string)$collection), $this->esc((string)$collection));
			foreach ($nodes as $node) {
				$nid     = $this->nodeId((string)$node['id']);
				$label   = $this->esc((string)($node['label'] ?? $node['id']));
				$lines[] = !empty($node['focal'])
					? sprintf('        %s["%s"]:::focal', $nid, $label)
					: sprintf('        %s["%s"]', $nid, $label);
			}
			$lines[] = '    end';
		}

		foreach ($graph['edges'] as $edge) {
			$from    = $this->nodeId((string)$edge['from']);
			$to      = $this->nodeId((string)$edge['to']);
			$label   = $this->esc((string)($edge['via'] ?? ''));
			$lines[] = $label !== ''
				? sprintf('    %s -->|%s| %s', $from, $label, $to)
				: sprintf('    %s --> %s', $from, $to);
		}

		$lines[] = '    classDef focal stroke-width:3px;';

		return implode("\n", $lines) . "\n";
	}

	private function nodeId(string $key): string
	{
		if (isset($this->nodeIds[$key])) {
			return $this->nodeIds[$key];
		}

		$base = (string)preg_replace('/[^A-Za-z0-9_]/', '_', $key);
		if ($base === '' || ctype_digit($base[0])) {
			$base = 'n_' . $base;
		}

		$id = $base;
		$i  = 2;
		while (isset($this->taken[$id])) {
			$id = $base . '_' . $i++;
		}

		$this->taken[$id]     = true;
		$this->nodeIds[$key]  = $id;

		return $id;
	}

	private function subgraphId(string $collection): string
	{
		$token = (string)preg_replace('/[^A-Za-z0-9_]/', '_', $collection);

		return 'sg_' . ($token !== '' ? $token : 'x');
	}

	private function esc(string $value): string
	{
		// Quoted Mermaid labels can't contain a double quote; brackets confuse
		// the flowchart parser even inside quotes. Newlines and `%` can inject
		// Mermaid directives (%%{init}%%, click callbacks, etc.).
		return str_replace(['"', '[', ']', "\n", "\r", '%'], ['', '(', ')', '', '', ''], $value);
	}
}
