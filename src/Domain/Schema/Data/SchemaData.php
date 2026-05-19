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
		'number',
		'builder-page',
		'playground',
		'preset-item',
		'sitemap-meta',
		'styledtext',
		'svg',
		'text',
		'toggle',
		'url',
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
