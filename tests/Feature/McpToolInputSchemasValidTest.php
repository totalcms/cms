<?php

declare(strict_types=1);

use Mcp\Capability\Discovery\SchemaValidator;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Support\Config;

/**
 * Wire-format conformance tests for every registered MCP tool's inputSchema.
 *
 * Catches issues that only surface when a strict MCP client (e.g. Inspector)
 * serialises the schema to JSON and validates against it — PHP-side assertions
 * on array values cannot distinguish `[]` (JSON array) from `{}` (JSON object).
 *
 * Two concrete bugs this test was written to catch:
 *
 *   1. SavedQueryToolFactory::inputSchemaFor() produced `"properties":[]` for
 *      tools with no params. JSON array is invalid — strict clients reject it.
 *      Fix: cast to \stdClass so json_encode emits `{}`.
 *
 *   2. SchemaTools::mutationInputSchema() had `extra: {type: object}`.
 *      mcp/sdk ~0.5's SchemaValidator::convertDataForValidator() only promotes
 *      non-empty assoc arrays to stdClass; JSON `{}` arrives as PHP `[]` and
 *      fails strict `type:object` validation.
 *      Fix: changed to `oneOf: [{type:object},{type:array,maxItems:0}]`.
 *
 * Assertions:
 *   A — json_encode shape: `properties` key (at any nesting level) must
 *       serialise as a JSON object (`{…}` or `{}`), never as `[]`.
 *   B — SDK SchemaValidator round-trip with empty args: must not throw.
 *       For tools where all params are optional, expects zero errors.
 *   C — Optional type:object properties: both stdClass and [] must be accepted,
 *       OR the schema must use a permissive oneOf shape.
 *   D — Required-param happy-path for schema-defined tools: synthetic stub
 *       args filling every required param must pass the validator.
 *
 * Structure mirrors McpToolOutputSchemasValidTest.php.
 */

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────────────────────────────────────

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Install the listings schema-tool fixture so SchemaToolRegistrar has at
	// least one schema-defined tool to exercise. Mirrors McpSchemaToolsIntegrationTest.
	$src  = __DIR__ . '/../fixtures/mcp/listings';
	$dest = cmsDataDir() . 'listings';
	if (!is_dir($dest)) {
		mkdir($dest, 0755, true);
	}

	copy($src . '/.meta.json', $dest . '/.meta.json');
	copy($src . '/.index.json', $dest . '/.index.json');
	foreach (glob($src . '/objects/*.json') ?: [] as $file) {
		copy($file, $dest . '/' . basename($file));
	}
});

afterEach(function (): void {
	recursiveDelete(cmsDataDir() . 'listings');
});

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Build the MCP server for the given persona and return all registered tools.
 * Triggers SchemaToolRegistrar so schema-defined tools are included.
 *
 * @return list<McpToolDefinition>
 */
function inputSchemaTestToolsFor(Slim\App $app, McpPersona $persona): array
{
	/** @var Config $config */
	$config = $app->getContainer()->get(Config::class);

	// Enable public access so the public persona resolves tools.
	$config->mcp['publicAccess'] = true;

	/** @var McpServerFactory $factory */
	$factory = $app->getContainer()->get(McpServerFactory::class);

	// build() triggers SchemaToolRegistrar and populates the registry.
	$factory->build($persona);

	/** @var ToolRegistry $registry */
	$registry = $app->getContainer()->get(ToolRegistry::class);

	return $registry->forPersona($persona);
}

/**
 * Recursively walk a PHP array (schema) and collect any key whose value would
 * serialise as `[]` (JSON array) but is declared with the key `properties`.
 * Returns a list of JSON-pointer-style paths where the violation was found.
 *
 * @param array<string,mixed>|mixed $schema
 *
 * @return list<string>
 */
function propertiesArrayViolations(mixed $schema, string $path = '$'): array
{
	if (!is_array($schema)) {
		return [];
	}

	$violations = [];

	if (array_key_exists('properties', $schema)) {
		$props = $schema['properties'];
		// A PHP empty array with no keys is indistinguishable from [] vs {} in
		// the PHP array type, but json_encode will emit `[]` for an empty list.
		// We detect: $props is a plain PHP array (not stdClass), AND it is
		// either empty OR is a sequential (list) array — both would become `[]`.
		if (is_array($props) && (empty($props) || array_is_list($props))) {
			$violations[] = "{$path}.properties serialises as `[]` — must be `{}` (use \\stdClass for empty)";
		}

		// Also recurse into every declared property sub-schema.
		if (is_array($props)) {
			foreach ($props as $name => $subSchema) {
				$violations = array_merge($violations, propertiesArrayViolations($subSchema, "{$path}.properties.{$name}"));
			}
		}
	}

	// Recurse into composition keywords.
	foreach (['oneOf', 'anyOf', 'allOf'] as $kw) {
		if (isset($schema[$kw]) && is_array($schema[$kw])) {
			foreach ($schema[$kw] as $i => $sub) {
				$violations = array_merge($violations, propertiesArrayViolations($sub, "{$path}.{$kw}[{$i}]"));
			}
		}
	}

	// Recurse into items.
	if (isset($schema['items'])) {
		if (is_array($schema['items']) && array_is_list($schema['items'])) {
			foreach ($schema['items'] as $i => $sub) {
				$violations = array_merge($violations, propertiesArrayViolations($sub, "{$path}.items[{$i}]"));
			}
		} else {
			$violations = array_merge($violations, propertiesArrayViolations($schema['items'], "{$path}.items"));
		}
	}

	return $violations;
}

/**
 * Return a list of property names (from the top-level `properties` map) whose
 * schema declares `type: object` directly (not via oneOf/anyOf/allOf).
 * These are the candidates for Assertion C.
 *
 * @param array<string,mixed> $inputSchema
 *
 * @return list<string>
 */
function nakedObjectTypeProperties(array $inputSchema): array
{
	$names = [];
	if (!isset($inputSchema['properties']) || !is_array($inputSchema['properties'])) {
		return $names;
	}

	foreach ($inputSchema['properties'] as $name => $propSchema) {
		if (!is_string($name) || !is_array($propSchema)) {
			continue;
		}

		// Only flag bare `type: object` — NOT `type: object` buried inside oneOf.
		// A oneOf is the fix; a bare `type: object` at the top level is the bug.
		$hasOneOf    = isset($propSchema['oneOf']) || isset($propSchema['anyOf']) || isset($propSchema['allOf']);
		$isBareObject = isset($propSchema['type']) && $propSchema['type'] === 'object' && !$hasOneOf;

		if ($isBareObject) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * Build a stub args stdClass that satisfies every `required` field in the
 * inputSchema. Uses type-appropriate sentinel values.
 *
 * @param array<string,mixed> $inputSchema
 */
function stubArgsFor(array $inputSchema): \stdClass
{
	$required = $inputSchema['required'] ?? [];
	if (!is_array($required)) {
		return new \stdClass();
	}

	$properties = $inputSchema['properties'] ?? [];

	$obj = new \stdClass();
	foreach ($required as $name) {
		if (!is_string($name)) {
			continue;
		}

		$propSchema = is_array($properties) ? ($properties[$name] ?? []) : [];
		$type       = is_array($propSchema) ? ($propSchema['type'] ?? 'string') : 'string';

		$obj->{$name} = match ($type) {
			'integer'        => 42,
			'number'         => 3.14,
			'boolean'        => true,
			'array'          => [],
			'object'         => new \stdClass(),
			default          => 'sample',
		};
	}

	return $obj;
}

// ──────────────────────────────────────────────────────────────────────────────
// Helper self-tests (fast, no app bootstrap required)
// ──────────────────────────────────────────────────────────────────────────────

describe('inputSchema helper self-tests', function (): void {
	it('propertiesArrayViolations catches empty plain PHP array for properties', function (): void {
		$schema = [
			'type'       => 'object',
			'properties' => [],            // <-- would json_encode as `[]`
		];

		expect(propertiesArrayViolations($schema))->not->toBeEmpty();
	});

	it('propertiesArrayViolations does not flag stdClass for properties', function (): void {
		$schema = [
			'type'       => 'object',
			'properties' => new \stdClass(), // json_encode emits `{}`
		];

		// stdClass is not an array, so our check is skipped.
		expect(propertiesArrayViolations($schema))->toBe([]);
	});

	it('propertiesArrayViolations does not flag non-empty associative array', function (): void {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'id' => ['type' => 'string'],
			],
		];

		expect(propertiesArrayViolations($schema))->toBe([]);
	});

	it('nakedObjectTypeProperties flags bare type:object but not oneOf shape', function (): void {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'bare_obj'  => ['type' => 'object'],
				'safe_obj'  => [
					'oneOf' => [
						['type' => 'object'],
						['type' => 'array', 'maxItems' => 0],
					],
				],
				'a_string'  => ['type' => 'string'],
			],
		];

		$naked = nakedObjectTypeProperties($schema);
		expect($naked)->toBe(['bare_obj']);
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Sanity
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP tool inputSchemas — sanity', function (): void {
	it('the suite finds at least one tool registered for admin persona', function (): void {
		$tools = inputSchemaTestToolsFor($this->app, McpPersona::ADMIN);
		expect($tools)->not->toBeEmpty();
	});

	it('schema-defined find_listings tool is present in admin persona', function (): void {
		$tools     = inputSchemaTestToolsFor($this->app, McpPersona::ADMIN);
		$toolNames = array_map(static fn (McpToolDefinition $t): string => $t->name, $tools);
		expect($toolNames)->toContain('find_listings');
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Assertion A — JSON serialisation: properties must be `{}` or `{…}`, never `[]`
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP tool inputSchemas — Assertion A: properties wire format', function (): void {
	it('every admin tool inputSchema properties field serialises as a JSON object', function (): void {
		$failures = [];

		foreach (inputSchemaTestToolsFor($this->app, McpPersona::ADMIN) as $tool) {
			if ($tool->inputSchema === null) {
				continue;
			}

			$violations = propertiesArrayViolations($tool->inputSchema);
			if ($violations !== []) {
				$failures[] = sprintf(
					"%s:\n    - %s",
					$tool->name,
					implode("\n    - ", $violations),
				);
			}
		}

		expect($failures)->toBe(
			[],
			"Tools with `properties: []` (JSON array) in inputSchema:\n" . implode("\n", $failures),
		);
	});

	it('every public tool inputSchema properties field serialises as a JSON object', function (): void {
		$failures = [];

		foreach (inputSchemaTestToolsFor($this->app, McpPersona::PUBLIC_) as $tool) {
			if ($tool->inputSchema === null) {
				continue;
			}

			$violations = propertiesArrayViolations($tool->inputSchema);
			if ($violations !== []) {
				$failures[] = sprintf(
					"%s:\n    - %s",
					$tool->name,
					implode("\n    - ", $violations),
				);
			}
		}

		expect($failures)->toBe(
			[],
			"Tools with `properties: []` (JSON array) in inputSchema:\n" . implode("\n", $failures),
		);
	});

	it('every tool inputSchema with properties serialises the key as `{` not `[` in JSON', function (): void {
		// Belt-and-suspenders: actually run json_encode and check the emitted
		// string. Catches any path where the PHP-side check above might miss.
		$failures = [];

		foreach (inputSchemaTestToolsFor($this->app, McpPersona::ADMIN) as $tool) {
			if ($tool->inputSchema === null) {
				continue;
			}

			$json = json_encode($tool->inputSchema);
			if ($json === false) {
				$failures[] = "{$tool->name}: json_encode failed — " . json_last_error_msg();
				continue;
			}

			// Detect the pattern "properties":[ — that's a JSON array, not object.
			if (preg_match('/"properties"\s*:\s*\[/', $json)) {
				$failures[] = "{$tool->name}: inputSchema json contains \"properties\":[ (must be object, not array). JSON: {$json}";
			}
		}

		expect($failures)->toBe(
			[],
			"Tools emitting `\"properties\":[` in serialised inputSchema:\n" . implode("\n", $failures),
		);
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Assertion B — SDK SchemaValidator round-trip with empty args
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP tool inputSchemas — Assertion B: SDK SchemaValidator round-trip', function (): void {
	it('every admin tool inputSchema does not throw when validated against empty args', function (): void {
		$validator    = new SchemaValidator();
		$emptyArgs    = new \stdClass();
		$noThrowFails = [];

		foreach (inputSchemaTestToolsFor($this->app, McpPersona::ADMIN) as $tool) {
			if ($tool->inputSchema === null) {
				continue;
			}

			// Must not throw — errors are returned as a list, not exceptions.
			try {
				$errors = $validator->validateAgainstJsonSchema($emptyArgs, $tool->inputSchema);
			} catch (\Throwable $e) {
				$noThrowFails[] = "{$tool->name}: SchemaValidator threw {$e->getMessage()}";
				continue;
			}

			// For tools with required params, errors are expected (missing required
			// fields). For tools with NO required params, there should be no errors.
			$required = $tool->inputSchema['required'] ?? [];
			if (is_array($required) && $required === []) {
				if ($errors !== []) {
					$errorMessages = array_column($errors, 'message');
					$noThrowFails[] = sprintf(
						"%s: unexpected validation errors for empty args (no required params): %s",
						$tool->name,
						implode('; ', $errorMessages),
					);
				}
			}
		}

		expect($noThrowFails)->toBe(
			[],
			"SchemaValidator failures:\n" . implode("\n", $noThrowFails),
		);
	});

	it('empty PHP array [] is treated equivalently to empty stdClass by the SchemaValidator', function (): void {
		// The SDK converts empty array to stdClass internally, so both inputs
		// should produce the same validation outcome against a no-required schema.
		$validator  = new SchemaValidator();
		$noParamsSchema = [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];

		$errorsWithStdClass = $validator->validateAgainstJsonSchema(new \stdClass(), $noParamsSchema);
		$errorsWithEmptyArr = $validator->validateAgainstJsonSchema([], $noParamsSchema);

		expect($errorsWithStdClass)->toBe([], 'stdClass{} should pass a no-params schema');
		expect($errorsWithEmptyArr)->toBe([], '[] should pass a no-params schema (SDK normalises empty array)');
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Assertion C — optional type:object properties accept both {} and []
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP tool inputSchemas — Assertion C: optional object properties', function (): void {
	it('optional type:object properties must use a permissive oneOf shape (not bare type:object)', function (): void {
		// A bare `type: object` on an optional property fails when the client
		// sends `{}` (which the SDK delivers as PHP `[]`).
		// The fix is `oneOf: [{type:object},{type:array,maxItems:0}]`.
		// This test enforces that bare naked `type:object` on optional properties
		// is not used — directing authors toward the safe pattern.
		$failures = [];

		foreach (inputSchemaTestToolsFor($this->app, McpPersona::ADMIN) as $tool) {
			if ($tool->inputSchema === null) {
				continue;
			}

			$requiredFields = $tool->inputSchema['required'] ?? [];
			if (!is_array($requiredFields)) {
				$requiredFields = [];
			}

			$nakedObjProps = nakedObjectTypeProperties($tool->inputSchema);

			foreach ($nakedObjProps as $propName) {
				// Only flag optional properties — a required object prop is fine
				// (the agent must supply a real value, not `{}`).
				if (!in_array($propName, $requiredFields, true)) {
					$failures[] = sprintf(
						"%s.properties.%s: optional property has bare `type:object` — must use oneOf permissive shape. "
						. "Hint: oneOf:[{type:object},{type:array,maxItems:0}] accepts both stdClass and [].",
						$tool->name,
						$propName,
					);
				}
			}
		}

		expect($failures)->toBe(
			[],
			"Tools with optional bare `type:object` property (SDK bug class):\n" . implode("\n", $failures),
		);
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Assertion D — required-param happy-path for schema-defined tools
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP tool inputSchemas — Assertion D: schema-defined tool required-param happy-path', function (): void {
	it('schema-defined tools pass SchemaValidator when all required params are provided', function (): void {
		$validator = new SchemaValidator();
		$failures  = [];

		$tools = inputSchemaTestToolsFor($this->app, McpPersona::ADMIN);

		// Schema-defined tools registered by SchemaToolRegistrar have inputSchema
		// built by SavedQueryToolFactory::inputSchemaFor(). They never have
		// outputSchema (only core tools add that), so we use that as a proxy.
		// A more reliable marker: SchemaToolRegistrar registers no descriptionBuilder.
		// The most reliable: check that the inputSchema was built by SavedQueryToolFactory
		// — those tools have only string/integer/number/boolean params (no `object` type).
		foreach ($tools as $tool) {
			$schema = $tool->inputSchema;
			if ($schema === null) {
				continue;
			}

			$required = $schema['required'] ?? [];
			if (!is_array($required) || $required === []) {
				// No required params — nothing to test here (covered by Assertion B).
				continue;
			}

			$stubArgs = stubArgsFor($schema);
			try {
				$errors = $validator->validateAgainstJsonSchema($stubArgs, $schema);
			} catch (\Throwable $e) {
				$failures[] = "{$tool->name}: SchemaValidator threw on stub args: {$e->getMessage()}";
				continue;
			}

			if ($errors !== []) {
				$errorMessages = array_column($errors, 'message');
				$failures[]    = sprintf(
					"%s: stub args failed validation: %s",
					$tool->name,
					implode('; ', $errorMessages),
				);
			}
		}

		expect($failures)->toBe(
			[],
			"Schema-defined tools that fail validation with stub required args:\n" . implode("\n", $failures),
		);
	});

	it('find_listings (schema-defined, has required city param) passes with stub args', function (): void {
		$tools = inputSchemaTestToolsFor($this->app, McpPersona::ADMIN);

		$findListings = null;
		foreach ($tools as $tool) {
			if ($tool->name === 'find_listings') {
				$findListings = $tool;
				break;
			}
		}

		if ($findListings === null || $findListings->inputSchema === null) {
			// Fixture not installed or MCP unavailable — skip-safe.
			expect(true)->toBeTrue();

			return;
		}

		$stubArgs = stubArgsFor($findListings->inputSchema);

		// city is required (string) — stub fills it with 'sample'.
		expect(isset($stubArgs->city))->toBeTrue('find_listings inputSchema must have city in required');

		$validator = new SchemaValidator();
		$errors    = $validator->validateAgainstJsonSchema($stubArgs, $findListings->inputSchema);

		expect($errors)->toBe(
			[],
			'find_listings stub args should pass inputSchema validation: ' . json_encode($errors),
		);
	});
});
