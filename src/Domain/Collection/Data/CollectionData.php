<?php

namespace TotalCMS\Domain\Collection\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Data object.
 */
final class CollectionData
{
	// Reserved names that cannot be used for collections
	public const RESERVED_NAMES = [
		'templates',
		'logs',
		'.schemas',
		'schemas',
	];

	private Serializer $serializer;

	public string $id;               // collection id
	public string $name;             // collection name
	public string $description;      // collection description
	public string $schema;           // schema name
	public string $url;              // collection url to object page minus the slug

	/** @var array<string,mixed> */
	public array $properties;        // Rules for fields defined in schemaToMetaProps

	/** @var array<string,array<string,mixed>> */
	public array $customProperties;  // Custom properties for specific objects

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		if (!$this->isValid()) {
			throw new \RuntimeException('CollectionData is not valid.');
		}
		$collection = [
			'id'          => $this->id,
			'schema'      => $this->schema,
			'name'        => $this->name ?? ucfirst($this->id),
			'description' => $this->description ?? "A collection of {$this->id} objects that conform to the {$this->schema} schema.",
			'url'         => $this->url ?? '',
			'properties'  => $this->properties ?? new \stdClass(),
		];

		if (!empty($this->customProperties)) {
			$collection['customProperties'] = $this->customProperties;
		}

		return $collection;
	}

	public function isValid(): bool
	{
		return isset($this->id) && isset($this->schema);
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
	}

	/**
	 * @param array<string,array<string,mixed>> $schema
	 * @return array<string,mixed>
	 */
	public static function schemaToMetaProps(array $schema): array
	{
		$metaProps = ['label', 'help', 'placeholder', 'field', 'factory'];

		foreach ($schema as $key => $prop) {
			// Only keep the meta properties that we need from the schema
			$schema[$key] = array_filter($prop, function ($key) use ($metaProps) {
				return in_array($key, $metaProps);
			}, ARRAY_FILTER_USE_KEY);
		}

		return $schema;
	}
}
