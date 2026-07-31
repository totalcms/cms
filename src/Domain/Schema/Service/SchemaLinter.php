<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Lints a stored schema and reports problems without saving anything.
 *
 * schema:import validates on the way in, but agents and operators edit schema
 * JSON in place — those files are never re-validated until something breaks at
 * runtime. The linter runs the same structural checks (plus a few save-time
 * normalizations silently paper over) against schemas already on disk.
 *
 * Errors are structural problems that will break at runtime; warnings are
 * agent-legibility gaps (property help text feeds the MCP tool catalog — it is
 * the only documentation an AI agent has for the content model).
 */
readonly class SchemaLinter
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private SchemaValidator $schemaValidator,
		private DeckCompatibilityChecker $deckChecker,
	) {
	}

	/**
	 * Lint one schema by id.
	 *
	 * @return array{errors: list<string>, warnings: list<string>}
	 */
	public function lint(string $schemaId): array
	{
		$errors   = [];
		$warnings = [];

		try {
			$raw = $this->schemaFetcher->fetchRawSchema($schemaId);
		} catch (\Throwable $e) {
			return ['errors' => ["Schema cannot be loaded: {$e->getMessage()}"], 'warnings' => []];
		}

		if ($raw->properties === []) {
			$errors[] = 'Schema defines no properties.';
		}

		// Missing parents are silently skipped when inheritance is resolved at
		// runtime, so a typo in inheritFrom loses every inherited property
		// without an error anywhere — surface it here.
		foreach ($raw->inheritFrom as $parentId) {
			if (!$this->schemaFetcher->schemaExists((string)$parentId)) {
				$errors[] = "inheritFrom references missing schema '{$parentId}'.";
			}
		}

		$flattened = $this->schemaFetcher->fetchSchema($schemaId);
		$defined   = array_keys($flattened->properties);

		// Save-time code auto-adds `id` to required/index but nothing ever
		// checks the property itself exists — object generation assumes it.
		if (!in_array('id', $defined, true)) {
			$errors[] = "No 'id' property is defined (own or inherited). Every schema needs one.";
		}

		foreach ($raw->required as $name) {
			if (!in_array($name, $defined, true)) {
				$errors[] = "required lists '{$name}', which is not a defined property.";
			}
		}
		foreach ($raw->index as $name) {
			if (!in_array($name, $defined, true)) {
				$errors[] = "index lists '{$name}', which is not a defined property.";
			}
		}

		foreach ($raw->properties as $name => $property) {
			if (!is_array($property)) {
				$errors[] = "Property '{$name}' is not an object.";
				continue;
			}
			array_push($errors, ...$this->lintSchemaRef($name, $property));
			if (trim((string)($property['help'] ?? '')) === '') {
				$warnings[] = "Property '{$name}' has no help text — help feeds the MCP tool catalog AI agents read.";
			}
		}

		if (trim($raw->description) === '') {
			$warnings[] = 'Schema has no description.';
		}

		array_push($errors, ...$this->metaValidate($raw));

		return ['errors' => $errors, 'warnings' => $warnings];
	}

	/**
	 * Deck/card properties point at a child schema via `schemaref` — the meta
	 * validator does not resolve those, so a deleted or misspelled child schema
	 * only fails when an editor opens the form.
	 *
	 * @param array<string,mixed> $property
	 *
	 * @return list<string>
	 */
	private function lintSchemaRef(string $name, array $property): array
	{
		$ref = (string)($property['$ref'] ?? '');
		if (!str_ends_with($ref, '/deck.json') && !str_ends_with($ref, '/card.json')) {
			return [];
		}

		$schemaRef = (string)($property['schemaref'] ?? '');
		if ($schemaRef === '') {
			return ["Property '{$name}' is a " . basename($ref, '.json') . " but has no schemaref."];
		}

		$childId = SchemaFetcher::extractSchemaId($schemaRef);
		if (!$this->schemaFetcher->schemaExists($childId)) {
			return ["Property '{$name}' references missing schema '{$childId}'."];
		}

		// Fetch the child ourselves and use the array-based checks: the
		// container builds DeckCompatibilityChecker without a SchemaFetcher
		// (optional ctor param), so its by-name methods always report
		// incompatible regardless of the schema.
		if (str_ends_with($ref, '/deck.json')) {
			$child = $this->schemaFetcher->fetchSchema($childId)->toArray();
			if (!$this->deckChecker->isCompatible($child)) {
				$bad = implode(', ', $this->deckChecker->getIncompatibleProperties($child));

				return ["Deck property '{$name}' uses schema '{$childId}' which contains deck-incompatible properties: {$bad}."];
			}
		}

		return [];
	}

	/**
	 * Run the same JSON-Schema meta validation a save would, after the same
	 * normalizations a save would apply — so the linter measures what
	 * schema:import would accept, not the un-normalized file bytes.
	 *
	 * @return list<string>
	 */
	private function metaValidate(SchemaData $raw): array
	{
		$data               = $raw->toArray();
		$data['properties'] = SchemaSaver::applyDefaultTypes((array)($data['properties'] ?? []));
		$data['properties'] = SchemaSaver::propertyTypeToRef($data['properties']);
		$data['properties'] = SchemaSaver::normalizeDefaultValues($data['properties']);

		try {
			$this->schemaValidator->validateSchema($data);
		} catch (\Throwable $e) {
			return [$e->getMessage()];
		}

		return [];
	}
}
