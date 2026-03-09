<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Report\Service;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Resolves available fields for report export from a collection's schema.
 *
 * Identifies scalar properties and deck properties with their sub-fields
 * by following deckref references to deck item schemas.
 */
readonly class ReportFieldResolver
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Get all available report fields for a collection.
	 *
	 * @return array{properties: array<string,string>, decks: array<string,array<string,string>>}
	 */
	public function resolve(string $collection): array
	{
		$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$properties = [];
		$decks      = [];

		foreach ($schema->properties as $name => $definition) {
			if ($this->isDeckProperty($definition)) {
				$deckFields = $this->resolveDeckFields($definition);
				if ($deckFields !== []) {
					$decks[$name] = $deckFields;
				}
				continue;
			}

			$properties[$name] = $this->resolveFieldType($definition);
		}

		// Always include 'id' as the first property, then sort the rest alphabetically
		unset($properties['id']);
		ksort($properties);
		$properties = ['id' => 'string'] + $properties;

		// Sort deck names and their fields alphabetically, with 'id' first
		ksort($decks);
		foreach ($decks as $deckName => $deckFields) {
			$deckId = $deckFields['id'] ?? null;
			unset($deckFields['id']);
			ksort($deckFields);
			if ($deckId !== null) {
				$deckFields = ['id' => $deckId] + $deckFields;
			}
			$decks[$deckName] = $deckFields;
		}

		return [
			'properties' => $properties,
			'decks'      => $decks,
		];
	}

	/**
	 * Render resolved fields as checkbox HTML for HTMX injection.
	 */
	public function renderHtml(string $collection): string
	{
		$fields = $this->resolve($collection);
		$html   = '';

		// Scalar properties
		if ($fields['properties'] !== []) {
			$html .= $this->sectionHeader('Properties');
			$html .= '<div class="report-field-grid">';
			foreach ($fields['properties'] as $name => $type) {
				$html .= $this->checkbox($name, $name, $type);
			}
			$html .= '</div>';
		}

		// Deck properties
		foreach ($fields['decks'] as $deckName => $deckFields) {
			$html .= $this->sectionHeader(htmlspecialchars($deckName, ENT_QUOTES, 'UTF-8'));
			$html .= '<div class="report-field-grid">';
			foreach ($deckFields as $fieldName => $type) {
				$dotName = $deckName . '.' . $fieldName;
				$html .= $this->checkbox($dotName, $fieldName, $type);
			}
			$html .= '</div>';
		}

		return $html;
	}

	private function sectionHeader(string $title): string
	{
		$toggle = HTMLUtils::element('button', '+', [
			'type'  => 'button',
			'class' => 'report-toggle-all',
			'title' => 'Select All',
		]);

		return HTMLUtils::element('h3', $title . ' ' . $toggle);
	}

	private function checkbox(string $value, string $label, string $type): string
	{
		$id    = 'report-field-' . str_replace('.', '-', $value);
		$input = HTMLUtils::inlineElement('input', [
			'type'  => 'checkbox',
			'name'  => 'fields[]',
			'value' => $value,
			'id'    => $id,
		]);

		return HTMLUtils::element('label', $input . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), [
			'for'   => $id,
			'title' => $type,
		]);
	}

	/**
	 * Check if a property definition is a deck type.
	 *
	 * @param array<string,mixed> $definition
	 */
	private function isDeckProperty(array $definition): bool
	{
		if (isset($definition['$ref']) && $definition['$ref'] === SchemaData::PROPERTY_TYPE_TO_REF['deck']) {
			return true;
		}

		return (isset($definition['field']) && $definition['field'] === 'deck')
			|| (isset($definition['type']) && $definition['type'] === 'deck');
	}

	/**
	 * Resolve deck sub-fields by following the deckref to its schema.
	 *
	 * @param array<string,mixed> $definition
	 *
	 * @return array<string,string>
	 */
	private function resolveDeckFields(array $definition): array
	{
		$deckref = $definition['deckref'] ?? '';
		if ($deckref === '') {
			return [];
		}

		$schemaId = SchemaFetcher::extractSchemaId($deckref);

		try {
			$deckSchema = $this->schemaFetcher->fetchSchema($schemaId);
		} catch (\Exception) {
			return [];
		}

		$fields = [];
		foreach ($deckSchema->properties as $fieldName => $fieldDef) {
			$fields[$fieldName] = $this->resolveFieldType($fieldDef);
		}

		return $fields;
	}

	/**
	 * Determine the field type string from a property definition.
	 *
	 * @param array<string,mixed> $definition
	 */
	private function resolveFieldType(array $definition): string
	{
		if (isset($definition['field'])) {
			return (string)$definition['field'];
		}

		if (isset($definition['type'])) {
			return (string)$definition['type'];
		}

		if (isset($definition['$ref'])) {
			$ref  = (string)$definition['$ref'];
			$type = array_search($ref, SchemaData::PROPERTY_TYPE_TO_REF, true);
			if ($type !== false) {
				return (string)$type;
			}
		}

		return 'string';
	}
}
