<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Data;

use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;

/**
 * Pure value object describing a single schema-defined MCP tool entry,
 * after JSON Schema validation. Constructed via the fromArray() factory
 * which enforces the same rules mcp-tool.json declares plus a few runtime
 * guards (limit clamping, operator allowlist).
 *
 * The tool's effective `access` is inherited from the parent collection's
 * `mcp.access` (passed into fromArray) — there is no per-tool override.
 */
readonly class SavedQueryToolDefinition
{
	private const ALLOWED_OPERATORS = [
		'eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'contains', 'starts', 'ends', 'in', 'notin',
	];

	private const ALLOWED_FORMATS = ['markdown', 'html', 'text'];

	private const ALLOWED_PARAM_TYPES = ['string', 'number', 'integer', 'boolean'];

	private const LIMIT_CAP = 50;

	/**
	 * @param array<string,array<string,mixed>> $params
	 * @param array<string,array{value:mixed,operator?:string}> $filters
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $collectionName,
		public string $access,
		public array $params,
		public array $filters,
		public string $sort,
		public int $limit,
		public int $offset,
		public string $include,
		public string $exclude,
		public string $format,
	) {
	}

	/**
	 * Accepts either an `id` key (preferred — matches the mcp-tool.json schema
	 * and the T3 convention) or a `name` key (legacy / programmatic
	 * construction). The PHP value-object property remains `$name` because
	 * that's the wire-level concept the MCP protocol uses when emitting tools.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(string $collectionName, string $access, array $data): self
	{
		$id = $data['id'] ?? $data['name'] ?? null;

		if (!is_string($id) || $id === '') {
			throw SavedQueryToolException::forValidation('?', 'missing required field: id');
		}

		if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
			throw SavedQueryToolException::forValidation($id, 'id must match ^[a-z][a-z0-9_]*$');
		}

		if (strlen($id) > 64) {
			throw SavedQueryToolException::forValidation($id, 'id must be <= 64 characters');
		}

		if (!isset($data['description']) || !is_string($data['description']) || $data['description'] === '') {
			throw SavedQueryToolException::forValidation($id, 'missing required field: description');
		}

		$params  = self::validateParams($id, is_array($data['params'] ?? null) ? $data['params'] : []);
		$filters = self::validateFilters($id, is_array($data['filters'] ?? null) ? $data['filters'] : []);

		$limit = (int)($data['limit'] ?? 20);
		if ($limit > self::LIMIT_CAP) {
			$limit = self::LIMIT_CAP;
		}

		if ($limit < 1) {
			$limit = 20;
		}

		$format = (string)($data['format'] ?? 'markdown');
		if (!in_array($format, self::ALLOWED_FORMATS, true)) {
			throw SavedQueryToolException::forValidation($id, "unsupported format: {$format}");
		}

		return new self(
			name: $id,
			description: $data['description'],
			collectionName: $collectionName,
			access: $access,
			params: $params,
			filters: $filters,
			sort: (string)($data['sort'] ?? ''),
			limit: $limit,
			offset: max(0, (int)($data['offset'] ?? 0)),
			include: (string)($data['include'] ?? ''),
			exclude: (string)($data['exclude'] ?? ''),
			format: $format,
		);
	}

	/**
	 * @param  array<array-key,mixed> $params
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function validateParams(string $toolName, array $params): array
	{
		$validated = [];
		foreach ($params as $name => $spec) {
			if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
				throw SavedQueryToolException::forValidation($toolName, "param name must match snake_case: {$name}");
			}

			if (!is_array($spec) || !isset($spec['type']) || !in_array($spec['type'], self::ALLOWED_PARAM_TYPES, true)) {
				throw SavedQueryToolException::forValidation($toolName, "param {$name}: invalid or missing type");
			}

			$validated[$name] = $spec;
		}

		return $validated;
	}

	/**
	 * @param  array<array-key,mixed> $filters
	 *
	 * @return array<string,array{value:mixed,operator?:string}>
	 */
	private static function validateFilters(string $toolName, array $filters): array
	{
		$validated = [];
		foreach ($filters as $field => $spec) {
			if (!is_string($field) || $field === '') {
				throw SavedQueryToolException::forValidation($toolName, 'filter field name must be non-empty string');
			}

			if (!is_array($spec) || !array_key_exists('value', $spec)) {
				throw SavedQueryToolException::forValidation($toolName, "filter {$field}: missing required 'value'");
			}

			$operator = $spec['operator'] ?? 'eq';
			if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
				throw SavedQueryToolException::forValidation($toolName, "unsupported operator: {$operator}");
			}

			$validated[$field] = ['value' => $spec['value'], 'operator' => $operator];
		}

		return $validated;
	}
}
