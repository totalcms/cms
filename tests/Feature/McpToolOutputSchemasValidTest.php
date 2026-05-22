<?php

declare(strict_types=1);

use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Structural validator for every registered MCP tool's outputSchema. Catches
 * the mistakes that actually matter:
 *
 *   - Typos in JSON Schema keyword names (`propeties` instead of `properties`)
 *   - Unknown type names (`int` instead of `integer`)
 *   - `required` arrays referencing properties that aren't defined
 *   - Nested sub-schemas with the same problems
 *
 * We validate the subset of JSON Schema 2020-12 vocabulary T3 actually uses
 * for tool output. Adding more keywords as we need them is a matter of
 * extending KNOWN_KEYWORDS.
 *
 * Why not full meta-schema validation: opis/json-schema can validate against
 * the official 2020-12 meta-schema but only when the schema document is local
 * (no remote fetch by default). Shipping a copy of the meta-schema as a
 * fixture would catch more theoretical edge cases, but the structural walker
 * below catches the practical ones at zero infrastructure cost.
 */

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Known JSON Schema keywords T3 outputSchemas may use. Anything outside this
 * list is flagged as a likely typo. Extend as new keywords are needed.
 */
const KNOWN_KEYWORDS = [
	'type', 'properties', 'required', 'items', 'additionalProperties',
	'description', 'enum', 'examples', 'default',
	'minimum', 'maximum', 'minLength', 'maxLength', 'pattern',
	'$ref', 'oneOf', 'anyOf', 'allOf', 'not',
	'minItems', 'maxItems', 'uniqueItems',
	'format', 'title', 'const',
];

const KNOWN_TYPES = ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'];

/**
 * Walk a schema (PHP array form) and return a list of structural problems.
 * Empty result = schema looks good.
 *
 * @param array<string,mixed>|mixed $schema
 * @return list<string>
 */
function structuralProblems(mixed $schema, string $path = '$'): array
{
	if (!is_array($schema)) {
		return ["{$path}: schema must be an array (got " . gettype($schema) . ')'];
	}

	$problems = [];

	// 1. All top-level keys must be known JSON Schema keywords.
	foreach ($schema as $key => $_) {
		if (!is_string($key)) {
			$problems[] = "{$path}: non-string key " . json_encode($key);
			continue;
		}
		if (!in_array($key, KNOWN_KEYWORDS, true)) {
			$problems[] = "{$path}: unknown keyword '{$key}' (typo? not in T3's known vocabulary)";
		}
	}

	// 2. `type` must be a known type name or a list of them.
	if (isset($schema['type'])) {
		$types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
		foreach ($types as $t) {
			if (!is_string($t) || !in_array($t, KNOWN_TYPES, true)) {
				$problems[] = "{$path}.type: unknown type '" . (is_string($t) ? $t : json_encode($t)) . "'";
			}
		}
	}

	// 3. `required` must be a list of strings, all referencing defined properties.
	if (isset($schema['required'])) {
		if (!is_array($schema['required'])) {
			$problems[] = "{$path}.required: must be an array";
		} else {
			$definedProps = is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];
			foreach ($schema['required'] as $req) {
				if (!is_string($req)) {
					$problems[] = "{$path}.required: non-string entry " . json_encode($req);
					continue;
				}
				if ($definedProps !== [] && !in_array($req, $definedProps, true)) {
					$problems[] = "{$path}.required: '{$req}' is not in properties (typo? property removed?)";
				}
			}
		}
	}

	// 4. `properties` must be a map of name => sub-schema; recurse.
	if (isset($schema['properties'])) {
		if (!is_array($schema['properties'])) {
			$problems[] = "{$path}.properties: must be an associative array";
		} else {
			foreach ($schema['properties'] as $name => $subSchema) {
				$problems = array_merge($problems, structuralProblems($subSchema, "{$path}.properties.{$name}"));
			}
		}
	}

	// 5. `items` must be a sub-schema (or list of them); recurse.
	if (isset($schema['items'])) {
		if (is_array($schema['items']) && array_is_list($schema['items'])) {
			foreach ($schema['items'] as $i => $sub) {
				$problems = array_merge($problems, structuralProblems($sub, "{$path}.items[{$i}]"));
			}
		} else {
			$problems = array_merge($problems, structuralProblems($schema['items'], "{$path}.items"));
		}
	}

	// 6. enum must be a list (any values allowed inside).
	if (isset($schema['enum']) && (!is_array($schema['enum']) || !array_is_list($schema['enum']))) {
		$problems[] = "{$path}.enum: must be a list";
	}

	// 7. Recurse into composition keywords.
	foreach (['oneOf', 'anyOf', 'allOf'] as $kw) {
		if (isset($schema[$kw])) {
			if (!is_array($schema[$kw])) {
				$problems[] = "{$path}.{$kw}: must be an array";
				continue;
			}
			foreach ($schema[$kw] as $i => $sub) {
				$problems = array_merge($problems, structuralProblems($sub, "{$path}.{$kw}[{$i}]"));
			}
		}
	}

	return $problems;
}

/**
 * @return list<array{name: string, schema: array<string,mixed>}>
 */
function toolsWithOutputSchemaFromRegistry(\Slim\App $app): array
{
	/** @var ToolRegistry $registry */
	$registry = $app->getContainer()->get(ToolRegistry::class);

	$tools = [];
	foreach ($registry->all() as $tool) {
		\assert($tool instanceof McpToolDefinition);
		if ($tool->outputSchema === null) {
			continue;
		}
		$tools[] = ['name' => $tool->name, 'schema' => $tool->outputSchema];
	}

	return $tools;
}

describe('MCP tool outputSchemas — structural validation', function (): void {
	it('the suite finds at least one tool with an outputSchema (sanity)', function (): void {
		expect(toolsWithOutputSchemaFromRegistry($this->app))->not->toBeEmpty();
	});

	it('every registered outputSchema passes structural checks', function (): void {
		$failures = [];
		foreach (toolsWithOutputSchemaFromRegistry($this->app) as $tool) {
			$problems = structuralProblems($tool['schema']);
			if ($problems !== []) {
				$failures[] = sprintf("%s:\n    - %s", $tool['name'], implode("\n    - ", $problems));
			}
		}

		expect($failures)->toBe([], "Invalid outputSchemas:\n" . implode("\n", $failures));
	});

	it('every registered outputSchema declares a top-level type', function (): void {
		$missing = [];
		foreach (toolsWithOutputSchemaFromRegistry($this->app) as $tool) {
			if (!isset($tool['schema']['type'])) {
				$missing[] = $tool['name'];
			}
		}

		expect($missing)->toBe([], 'Tools missing top-level `type`: ' . implode(', ', $missing));
	});

	it('every registered outputSchema can JSON-round-trip without data loss', function (): void {
		// Catches mistakes like un-encodable values (Closures, resources) sneaking
		// into a schema — the SDK serializes outputSchema to JSON for tools/list.
		$failures = [];
		foreach (toolsWithOutputSchemaFromRegistry($this->app) as $tool) {
			$json = json_encode($tool['schema']);
			if ($json === false) {
				$failures[] = "{$tool['name']}: json_encode failed - " . json_last_error_msg();
				continue;
			}
			$decoded = json_decode($json, true);
			if ($decoded !== $tool['schema']) {
				$failures[] = "{$tool['name']}: round-trip lost data (likely a non-scalar leaked in)";
			}
		}

		expect($failures)->toBe([], implode("\n", $failures));
	});
});

describe('Structural walker self-tests', function (): void {
	it('flags unknown keywords', function (): void {
		$problems = structuralProblems(['type' => 'object', 'propeties' => []]);
		expect(implode("\n", $problems))->toContain("unknown keyword 'propeties'");
	});

	it('flags required references to missing properties', function (): void {
		$problems = structuralProblems([
			'type'       => 'object',
			'required'   => ['id', 'ghost'],
			'properties' => ['id' => ['type' => 'string']],
		]);
		expect(implode("\n", $problems))->toContain("'ghost' is not in properties");
	});

	it('flags unknown type names', function (): void {
		$problems = structuralProblems(['type' => 'int']);
		expect(implode("\n", $problems))->toContain("unknown type 'int'");
	});

	it('returns no problems for a well-formed nested schema', function (): void {
		$problems = structuralProblems([
			'type'                 => 'object',
			'required'             => ['items', 'total'],
			'additionalProperties' => false,
			'properties'           => [
				'items' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'required'             => ['id'],
						'properties'           => [
							'id' => ['type' => 'string'],
						],
					],
				],
				'total' => ['type' => 'integer'],
			],
		]);
		expect($problems)->toBe([]);
	});
});
