<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Schema\Data;

/**
 * Typed representation of a schema property definition.
 *
 * Core keys are typed properties. Field-specific settings and
 * validation constraints are stored in $settings and $extra bags.
 */
readonly class PropertyDefinition
{
	/**
	 * @param array<mixed>        $options  Select/checkbox options
	 * @param array<string,mixed> $settings Field-specific configuration
	 * @param array<string,mixed> $extra    Validation constraints and unrecognized keys
	 */
	public function __construct(
		public ?string $type = null,
		public ?string $ref = null,
		public string $field = 'text',
		public string $label = '',
		public string $help = '',
		public string $placeholder = '',
		public mixed $default = null,
		public ?string $deckref = null,
		public ?string $deckItemLabel = null,
		public array $options = [],
		public array $settings = [],
		public array $extra = [],
	) {
	}

	/**
	 * Create from an associative array (JSON schema property definition).
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		// Extract deckref from settings if not at top level
		$deckref       = $data['deckref'] ?? $data['settings']['deckref'] ?? null;
		$deckItemLabel = $data['deckItemLabel'] ?? $data['settings']['deckItemLabel'] ?? null;

		$known = [
			'type', '$ref', 'field', 'label', 'help', 'placeholder',
			'default', 'deckref', 'deckItemLabel', 'options', 'settings',
		];

		$extra = array_diff_key($data, array_flip($known));

		return new self(
			type: isset($data['type']) ? (string)$data['type'] : null,
			ref: isset($data['$ref']) ? (string)$data['$ref'] : null,
			field: (string)($data['field'] ?? 'text'),
			label: (string)($data['label'] ?? ''),
			help: (string)($data['help'] ?? ''),
			placeholder: (string)($data['placeholder'] ?? ''),
			default: $data['default'] ?? null,
			deckref: $deckref !== null ? (string)$deckref : null,
			deckItemLabel: $deckItemLabel !== null ? (string)$deckItemLabel : null,
			options: is_array($data['options'] ?? null) ? $data['options'] : [],
			settings: is_array($data['settings'] ?? null) ? $data['settings'] : [],
			extra: $extra,
		);
	}

	/**
	 * Convert back to the array format expected by JSON schema storage.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$data = [];

		if ($this->type !== null) {
			$data['type'] = $this->type;
		}
		if ($this->ref !== null) {
			$data['$ref'] = $this->ref;
		}
		if ($this->field !== 'text' || $this->type === null) {
			$data['field'] = $this->field;
		}
		if ($this->label !== '') {
			$data['label'] = $this->label;
		}
		if ($this->help !== '') {
			$data['help'] = $this->help;
		}
		if ($this->placeholder !== '') {
			$data['placeholder'] = $this->placeholder;
		}
		if ($this->default !== null) {
			$data['default'] = $this->default;
		}
		if ($this->deckref !== null) {
			$data['deckref'] = $this->deckref;
		}
		if ($this->deckItemLabel !== null) {
			$data['deckItemLabel'] = $this->deckItemLabel;
		}
		if ($this->options !== []) {
			$data['options'] = $this->options;
		}
		if ($this->settings !== []) {
			$data['settings'] = $this->settings;
		}

		return array_merge($data, $this->extra);
	}

	/**
	 * Resolve the property type name from either $type or $ref.
	 * Replaces the scattered extractPropertyType() logic.
	 */
	public function resolveType(): string
	{
		// Try reverse lookup from $ref
		if ($this->ref !== null) {
			$reversed = array_search($this->ref, SchemaData::PROPERTY_TYPE_TO_REF, true);
			if ($reversed !== false) {
				return $reversed;
			}

			// Fallback: extract type from $ref basename (e.g. ".../string.json" → "string")
			$basename = basename($this->ref, '.json');
			if ($basename !== '') {
				return $basename;
			}
		}

		// Fall back to type field
		if ($this->type !== null) {
			return $this->type;
		}

		// Final fallback to field type
		return $this->field;
	}
}
