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
	 * The JSON type a `$ref`-declared property's values take, keyed by the
	 * property type the `$ref` names (resources/schemas/properties/). Types
	 * absent here hold strings. `color` (an anyOf of objects), `deck`,
	 * `localizedtext` and `json` declare no top-level type but store objects
	 * (json may also store an array), so they count as `object`. The JSON primitives map to themselves for a custom
	 * `$ref` named after one.
	 */
	private const REF_JSON_TYPES = [
		'list'          => 'array',
		'gallery'       => 'array',
		'card'          => 'object',
		'color'         => 'object',
		'deck'          => 'object',
		'depot'         => 'object',
		'file'          => 'object',
		'folder'        => 'object',
		'image'         => 'object',
		'json'          => 'object',
		'localizedtext' => 'object',
		'properties'    => 'object',
		'rating'        => 'object',
		'video'         => 'object',
		'slug'          => 'string',
		'empty'         => 'string',
		'array'         => 'array',
		'boolean'       => 'boolean',
		'integer'       => 'integer',
		'number'        => 'number',
		'string'        => 'string',
	];

	/**
	 * @param array<mixed>        $options   Select/checkbox options
	 * @param array<string,mixed> $settings  Field-specific configuration
	 * @param array<string,mixed> $extra     Validation constraints and unrecognized keys
	 */
	public function __construct(
		public ?string $type = null,
		public ?string $ref = null,
		public string $field = 'text',
		public string $label = '',
		public string $help = '',
		public string $placeholder = '',
		public mixed $default = null,
		public ?string $schemaref = null,
		public ?string $deckItemLabel = null,
		public array $options = [],
		public array $settings = [],
		public array $extra = [],
	) {
	}

	/**
	 * Backward-compatible accessor for the legacy `deckref` property name.
	 * Reads should prefer `$schemaref`.
	 */
	public function __get(string $name): mixed
	{
		if ($name === 'deckref') {
			return $this->schemaref;
		}

		return null;
	}

	/**
	 * Resolve a schema reference from a raw property config array.
	 * Accepts both the canonical `schemaref` key and the legacy `deckref` alias,
	 * at the top level or nested in `settings`.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function extractSchemaRef(array $data): ?string
	{
		$ref = $data['schemaref']
			?? $data['deckref']
			?? (is_array($data['settings'] ?? null) ? ($data['settings']['schemaref'] ?? $data['settings']['deckref'] ?? null) : null);

		if ($ref === null || $ref === '') {
			return null;
		}

		return (string)$ref;
	}

	/**
	 * The property type as a string. JSON Schema also allows a list
	 * (`"type": ["string", "null"]`), which SchemaSaver passes through
	 * untouched; the first non-null entry names the T3 type. Casting the
	 * list with (string) produced "Array" plus a warning that crashed
	 * `jumpstart:import` and `schema:lint` under the error handler.
	 */
	private static function typeFrom(mixed $type): ?string
	{
		if (is_array($type)) {
			foreach ($type as $entry) {
				if (is_string($entry) && $entry !== '' && $entry !== 'null') {
					return $entry;
				}
			}

			return null;
		}

		return is_scalar($type) ? (string)$type : null;
	}

	/**
	 * Create from an associative array (JSON schema property definition).
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$schemaref     = self::extractSchemaRef($data);
		$deckItemLabel = $data['deckItemLabel'] ?? $data['settings']['deckItemLabel'] ?? null;

		$known = [
			'type', '$ref', 'field', 'label', 'help', 'placeholder',
			'default', 'schemaref', 'deckref', 'deckItemLabel', 'options', 'settings',
		];

		$extra = array_diff_key($data, array_flip($known));

		return new self(
			type: self::typeFrom($data['type'] ?? null),
			ref: is_scalar($data['$ref'] ?? null) ? (string)$data['$ref'] : null,
			field: (string)($data['field'] ?? 'text'),
			label: (string)($data['label'] ?? ''),
			help: (string)($data['help'] ?? ''),
			placeholder: (string)($data['placeholder'] ?? ''),
			default: $data['default'] ?? null,
			schemaref: $schemaref,
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
		if ($this->schemaref !== null) {
			$data['schemaref'] = $this->schemaref;
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
	 * Whether this property stores a password hash — the same rule that makes
	 * the property a PasswordData at runtime. Password properties are never
	 * indexed: see SchemaSaver::sanitizeRequiredAndIndex() and IndexBuilder.
	 */
	public function isPassword(): bool
	{
		return $this->resolveType() === 'password';
	}

	/**
	 * The JSON type a property's values take, resolved through its `$ref` when
	 * the definition declares none: the property schemas under
	 * resources/schemas/properties/ declare `array` for list and gallery,
	 * `object` for card, depot, file, folder, image, properties, rating and
	 * video (color, deck, localizedtext and json store objects too), and
	 * `string` (or a format on a string) for every other one. An explicit `type` wins. Null
	 * for a definition with neither `type` nor `$ref` (the field decides;
	 * callers read that as a string) and for an unrecognised `$ref`.
	 */
	public function effectiveJsonType(): ?string
	{
		if ($this->type !== null) {
			return $this->type;
		}
		if ($this->ref === null) {
			return null;
		}

		$basename = basename($this->ref, '.json');
		if (isset(self::REF_JSON_TYPES[$basename])) {
			return self::REF_JSON_TYPES[$basename];
		}

		// A custom $ref (a card pointing at its sub-schema): resolveType()
		// falls back to the field type.
		$resolved = $this->resolveType();

		return self::REF_JSON_TYPES[$resolved]
			?? (in_array($resolved, SchemaData::PROPERTY_TYPES, true) ? 'string' : null);
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

			// Fallback: extract type from $ref basename — but only trust it when
			// it names one of the recognized property types. A custom $ref like
			// `.../my-card.json` (used by card properties whose validation $ref
			// points at the user's sub-schema) would otherwise yield "my-card",
			// which isn't a real type and would force the form's type dropdown
			// to fall through to its first option.
			$basename = basename($this->ref, '.json');
			if ($basename !== '' && in_array($basename, SchemaData::PROPERTY_TYPES, true)) {
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
