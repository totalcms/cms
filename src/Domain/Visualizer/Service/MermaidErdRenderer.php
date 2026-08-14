<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

/**
 * Renders a {@see RelationshipAnalyzer} graph as Mermaid `erDiagram` text.
 *
 * Mermaid entity names must be word characters, so collection ids (which often
 * contain hyphens or `view:`/`schema:` prefixes) are sanitized to safe, unique
 * tokens while the human label is carried in the entity alias (`ID["label"]`).
 */
final class MermaidErdRenderer
{
	/** @var array<string,string> node id => mermaid entity id */
	private array $entityIds = [];

	/** @var array<string,bool> taken entity ids (collision guard) */
	private array $taken = [];

	/** @var list<string> edge types, in the order relationship lines are emitted */
	private array $edgeTypes = [];

	/** Cap fields shown per entity so big schemas don't blow up the box size. */
	private const MAX_FIELDS = 10;

	/**
	 * All relationships use the most neutral crow's-foot notation (plain bars,
	 * no circles/feet). erDiagram always draws *some* end markers, and we don't
	 * model real cardinality — edge type is conveyed by colour + dash on the
	 * frontend instead (see DataVisualizer.styleEdges).
	 */
	private const RELATION = '||--||';

	/**
	 * @param array{nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>} $graph
	 */
	public function render(array $graph): string
	{
		$this->entityIds = [];
		$this->taken     = [];
		$this->edgeTypes = [];

		$lines = ['erDiagram'];

		foreach ($graph['nodes'] as $node) {
			$lines[] = $this->renderEntity($node);
		}

		// De-dupe exact (from,to,type,via) edges.
		$seen = [];
		foreach ($graph['edges'] as $edge) {
			$key = $edge['from'] . '|' . $edge['to'] . '|' . $edge['type'] . '|' . ($edge['via'] ?? '');
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;

			$from  = $this->entityId((string)$edge['from']);
			$to    = $this->entityId((string)$edge['to']);
			$label = $this->label((string)($edge['via'] ?? ''), (string)$edge['type']);

			$lines[]           = sprintf('    %s %s %s : "%s"', $from, self::RELATION, $to, $label);
			$this->edgeTypes[] = (string)$edge['type'];
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Edge types in the same order the relationship lines were emitted — lets
	 * the frontend colour/dash each rendered `.relationshipLine` path by type
	 * (Mermaid's erDiagram has no per-edge styling in the syntax).
	 *
	 * NOTE: This method returns an empty array until {@see render()} has been
	 * called. Always call render() first, then read edgeTypes().
	 *
	 * @return list<string>
	 */
	public function edgeTypes(): array
	{
		return $this->edgeTypes;
	}

	/** @param array<string,mixed> $node */
	private function renderEntity(array $node): string
	{
		$id    = $this->entityId((string)$node['id']);
		$label = $this->escapeAlias((string)($node['label'] ?? $node['id']));

		$fields = is_array($node['fields'] ?? null) ? $node['fields'] : [];
		if ($fields === []) {
			return sprintf('    %s["%s"]', $id, $label);
		}

		$rows  = [];
		$shown = 0;
		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}
			if ($shown >= self::MAX_FIELDS) {
				$more   = count($fields) - $shown;
				$rows[] = sprintf('        more fields_%d', $more);
				break;
			}
			$type   = $this->token((string)($field['type'] ?? 'string')) ?: 'string';
			$name   = $this->token((string)($field['name'] ?? '')) ?: 'field';
			$rows[] = sprintf('        %s %s', $type, $name);
			$shown++;
		}

		return sprintf("    %s[\"%s\"] {\n%s\n    }", $id, $label, implode("\n", $rows));
	}

	/** Stable, unique, word-char entity id for a node id. */
	private function entityId(string $nodeId): string
	{
		if (isset($this->entityIds[$nodeId])) {
			return $this->entityIds[$nodeId];
		}

		$base = (string)preg_replace('/[^A-Za-z0-9_]/', '_', $nodeId);
		if ($base === '' || ctype_digit($base[0])) {
			$base = 'n_' . $base;
		}

		$id = $base;
		$i  = 2;
		while (isset($this->taken[$id])) {
			$id = $base . '_' . $i++;
		}

		$this->taken[$id]          = true;
		$this->entityIds[$nodeId]  = $id;

		return $id;
	}

	/** Edge label: the property name, or the edge type when there's no property. */
	private function label(string $via, string $type): string
	{
		$via = trim($via);

		return $via !== '' ? $this->escapeAlias($via) : $type;
	}

	/**
	 * Mermaid attribute tokens (a field's type/name) must be word characters
	 * AND start with a letter or underscore — a leading digit is lexed as a
	 * number and breaks the parse, so prefix one when needed.
	 */
	private function token(string $value): string
	{
		$token = (string)preg_replace('/[^A-Za-z0-9_]/', '_', $value);
		if ($token === '' || preg_match('/^[A-Za-z_]/', $token) !== 1) {
			return '_' . $token;
		}

		return $token;
	}

	/** Escape a quoted alias/label so it can't break the Mermaid string. */
	private function escapeAlias(string $value): string
	{
		return str_replace(['"', "\n", "\r", '%'], '', $value);
	}
}
