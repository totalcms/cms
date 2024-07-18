<?php

namespace TotalCMS\Domain\Schema\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Schema Data object.
 */
final class SchemaData
{
	public const SCHEMA_PREFIX    = 'https://www.totalcms.co/schemas/';
	public const SCHEMA_VERSION   = 'https://json-schema.org/draft/2020-12/schema';
	public const RESERVED_SCHEMAS = [
		'blog',
		'color',
		'collection',
		'date',
		'depot',
		'email',
		'feed',
		'file',
		'gallery',
		'image',
		'number',
		'schema',
		'styledtext',
		'svg',
		'text',
		'toggle',
		'url',
	];
	public const PROPERTY_TYPES = [
		'string',
		'number',
		'boolean',
		'array',
		'object',
		'color',
		'date',
		'time',
		'deck',
		'email',
		'file',
		'image',
		'url',
		'depot',
		'gallery',
		'list',
		'password',
		'phone',
		'slug',
		'svg',
	];

	public string $id;
	public string $description;
	/** @var array<string,mixed> */
	public array $properties;
	/** @var array<string> */
	public array $required;
	/** @var array<string> */
	public array $index;
	protected Serializer $serializer;

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'$schema'     => self::SCHEMA_VERSION,
			'$id'         => self::SCHEMA_PREFIX . $this->id . '.json',
			'type'        => 'object',
			'id'          => $this->id,
			'description' => $this->description,
			'properties'  => $this->properties,
			'required'    => $this->required,
			'index'       => $this->index ?? [],
		];
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
	}
}
