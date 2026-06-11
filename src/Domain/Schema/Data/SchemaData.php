<?php

namespace TotalCMS\Domain\Schema\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Schema\Service\SchemaTransformer;

/**
 * Schema Data object.
 */
class SchemaData
{
	public const SCHEMA_PREFIX        = 'https://www.totalcms.co/schemas/';
	public const SCHEMA_CUSTOM_PREFIX = 'https://www.totalcms.co/schemas/custom/';
	public const SCHEMA_VERSION       = 'https://json-schema.org/draft/2020-12/schema';
	public const RESERVED_NAMES       = [
		'collection',
		'jumpstart',
		'new', // not allowed for /admin url routes
		'schema',
		'template',
	];
	public const RESERVED_SCHEMAS = [
		'auth',
		'automation-trigger',
		'automations',
		'blog-legacy',
		'blog',
		'code',
		'color',
		'date',
		'dataviews',
		'depot',
		'email',
		'feed',
		'file',
		'gallery',
		'image',
		'mailer',
		'mcp-collection',
		'mcp-prompt',
		'mcp-prompt-arg',
		'mcp-property',
		'mcp-tool',
		'number',
		'builder-page',
		'playground',
		'preset-item',
		'sitemap-meta',
		'styledtext',
		'svg',
		'text',
		'toggle',
		'totalcms',
		'totalcms-item',
		'url',
	];

	/**
	 * Reserved schemas that exist purely as reference/example definitions.
	 * They are registered and URL-resolvable (so schema tooling, the schema
	 * editor, and `schemaref` can see them) but can NEVER back a collection —
	 * every collection-creation path rejects them.
	 *
	 * @var list<string>
	 */
	public const REFERENCE_SCHEMAS = [
		'totalcms',
		'totalcms-item',
	];

	public static function isReferenceSchema(string $schemaId): bool
	{
		return in_array($schemaId, self::REFERENCE_SCHEMAS, true);
	}

	/**
	 * Reserved collections that can execute code or carry security-sensitive
	 * config, and are therefore writable through the generic object API only by
	 * a super-admin (their admin UI is already AdminOnly-gated). `automations`
	 * ships an `external mode:php` handler field — a direct RCE vector.
	 *
	 * @var list<string>
	 */
	public const SYSTEM_COLLECTIONS = [
		'automations',
	];
	public const PROPERTY_TYPES = [
		'array',
		'boolean',
		'card',
		'code',
		'color',
		'date',
		'deck',
		'depot',
		'email',
		'file',
		'gallery',
		'image',
		'json',
		'list',
		'localizedtext',
		'number',
		'password',
		'phone',
		'slug',
		'string',
		'svg',
		'time',
		'url',
	];

	/**
	 * Form-field types (the `field` key in property definitions) that have a
	 * meaningful include/exclude filter semantics. The truth is editorial —
	 * `ObjectFilter` can technically match a string against any scalar, but
	 * filtering on a styledtext blob or a media field by string equality is
	 * never useful. Container/blob fields (`card`, `deck`, `image`, `gallery`,
	 * `depot`, `file`, `svg`, `json`) are deliberately excluded.
	 *
	 * Consumed via `ObjectFilter::isFilterableType()`. Per-property operator
	 * overrides (e.g. `mcp.filterable: false`) win at the McpSchemaResolver
	 * layer; this constant only describes the default when none is set.
	 */
	public const FILTERABLE_FIELD_TYPES = [
		'text', 'textarea', 'styledtext', 'select', 'toggle', 'checkbox', 'time',
		'number', 'range', 'date', 'datetime', 'slug', 'string', 'id', 'email', 'url', 'phone',
	];

	/**
	 * Form-field types with a well-defined sort order. Strictly numeric and
	 * temporal types plus `id` (the slug-like primary key — `sort=id:asc` is
	 * the natural deterministic fallback when no other sortable column exists).
	 *
	 * Text-shaped types are intentionally absent: lexicographic sorting of
	 * styledtext / textarea bodies is rarely what callers want. Operators can
	 * still opt in per-property via `mcp.sortable: true`.
	 *
	 * Consumed via `CollectionSorter::isSortableType()`.
	 */
	public const SORTABLE_FIELD_TYPES = [
		'number', 'range', 'date', 'datetime', 'id',
	];

	/**
	 * Form-field types that hold credential / secret values. Properties using
	 * these types default to `mcp.expose: false` — they never appear in MCP
	 * responses unless the operator explicitly sets `mcp.expose: true`.
	 *
	 * The defensive default catches the common case (password hashes, API
	 * keys, OAuth tokens stored in plain text via SecretField) without
	 * requiring every operator to remember to opt out per-schema.
	 *
	 * Consumed via `McpSchemaResolver::isPropertyExposed()`.
	 */
	public const SENSITIVE_FIELD_TYPES = [
		'password',
		'secret',
	];
	public const PROPERTY_TYPE_TO_REF = [
		'card'          => 'https://www.totalcms.co/schemas/properties/card.json',
		'code'          => 'https://www.totalcms.co/schemas/properties/code.json',
		'color'         => 'https://www.totalcms.co/schemas/properties/color.json',
		'date'          => 'https://www.totalcms.co/schemas/properties/date.json',
		'deck'          => 'https://www.totalcms.co/schemas/properties/deck.json',
		'depot'         => 'https://www.totalcms.co/schemas/properties/depot.json',
		'email'         => 'https://www.totalcms.co/schemas/properties/email.json',
		'file'          => 'https://www.totalcms.co/schemas/properties/file.json',
		'gallery'       => 'https://www.totalcms.co/schemas/properties/gallery.json',
		'image'         => 'https://www.totalcms.co/schemas/properties/image.json',
		'json'          => 'https://www.totalcms.co/schemas/properties/json.json',
		'list'          => 'https://www.totalcms.co/schemas/properties/list.json',
		'localizedtext' => 'https://www.totalcms.co/schemas/properties/localizedtext.json',
		'password'      => 'https://www.totalcms.co/schemas/properties/password.json',
		'phone'         => 'https://www.totalcms.co/schemas/properties/phone.json',
		'rating'        => 'https://www.totalcms.co/schemas/properties/rating.json',
		'slug'          => 'https://www.totalcms.co/schemas/properties/slug.json',
		'svg'           => 'https://www.totalcms.co/schemas/properties/svg.json',
		'time'          => 'https://www.totalcms.co/schemas/properties/time.json',
		'url'           => 'https://www.totalcms.co/schemas/properties/url.json',
	];

	public string $id          = '';
	public string $formgrid    = '';
	public string $description = '';
	public string $category    = '';
	/** @var array<string,mixed> */
	public array $properties = [];
	/** @var array<string> */
	public array $required = [];
	/** @var array<string> */
	public array $index = [];
	/** @var array<string> */
	public array $inheritFrom = [];
	protected Serializer $serializer;

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		// Use custom prefix for non-reserved schemas, default prefix for reserved schemas
		$prefix = in_array($this->id, self::RESERVED_SCHEMAS, true)
			? self::SCHEMA_PREFIX
			: self::SCHEMA_CUSTOM_PREFIX;

		$array = [
			'$schema'     => self::SCHEMA_VERSION,
			'$id'         => $prefix . $this->id . '.json',
			'type'        => 'object',
			'id'          => $this->id,
			'description' => $this->description,
			'properties'  => $this->properties,
			'required'    => $this->required,
			'index'       => $this->index,
		];

		// Only include formgrid if it's not empty
		if ($this->formgrid !== '') {
			$array['formgrid'] = $this->formgrid;
		}

		// Only include category if it's not empty
		if ($this->category !== '') {
			$array['category'] = $this->category;
		}

		// Only include inheritFrom if it's not empty
		if ($this->inheritFrom !== []) {
			$array['inheritFrom'] = $this->inheritFrom;
		}

		// Apply schema transformations to expand simplified deck syntax
		$transformer = new SchemaTransformer();

		return $transformer->transformSchema($array);
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
	}
}
