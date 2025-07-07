<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Data;

final class JumpStartData
{
	public string $version = '1.0.0';

	/** @var array<int, array<string, mixed>> */
	public array $schemas = [];

	/** @var array<string, mixed> */
	public array $collections = [
		'reserved' => [],
		'custom' => []
	];

	/** @var array<int, array<string, mixed>> */
	public array $objects = [];

	/** @var array<int, array<string, mixed>> */
	public array $factory = [];

	public function __construct(
		public string $name        = 'Exported from Current CMS Data',
		public string $description = 'Jumpstart definition generated from existing Total CMS data',
	){
		$this->description .= ' - ' . date('Y-m-d H:i:s');
	}

	/**
	 * Add a custom schema to the jumpstart definition
	 * @param array<string, mixed> $schema
	 */
	public function addSchema(array $schema): void
	{
		$this->schemas[] = $schema;
	}

	/**
	 * Add a reserved collection to the jumpstart definition
	 */
	public function addReservedCollection(string $collectionType): void
	{
		if (!in_array($collectionType, $this->collections['reserved'])) {
			$this->collections['reserved'][] = $collectionType;
		}
	}

	/**
	 * Add a custom collection to the jumpstart definition
	 * @param array<string, mixed> $collection
	 */
	public function addCustomCollection(array $collection): void
	{
		$this->collections['custom'][] = $collection;
	}

	/**
	 * Add an object to the jumpstart definition
	 * @param array<string, mixed> $object
	 */
	public function addObject(array $object): void
	{
		$this->objects[] = $object;
	}

	/**
	 * Add a factory definition to the jumpstart definition
	 * @param array<string, mixed> $factoryDef
	 */
	public function addFactory(array $factoryDef): void
	{
		$this->factory[] = $factoryDef;
	}

	/**
	 * Convert to array for JSON export
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'version'     => $this->version,
			'name'        => $this->name,
			'description' => $this->description,
			'schemas'     => $this->schemas,
			'collections' => $this->collections,
			'objects'     => $this->objects,
			'factory'     => $this->factory
		];
	}

	/**
	 * Create from array (for JSON import)
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$jumpstart = new self(
			$data['name'] ?? '',
			$data['description'] ?? ''
		);

		$jumpstart->version     = $data['version'] ?? '1.0.0';
		$jumpstart->schemas     = $data['schemas'] ?? [];
		$jumpstart->collections = $data['collections'] ?? ['default' => [], 'custom' => []];
		$jumpstart->objects     = $data['objects'] ?? [];
		$jumpstart->factory     = $data['factory'] ?? [];

		return $jumpstart;
	}

	/**
	 * Export to JSON string
	 */
	public function toJson(): string
	{
		$json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode jumpstart data to JSON');
		}
		return $json;
	}

	/**
	 * Create from JSON string
	 */
	public static function fromJson(string $json): self
	{
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new \InvalidArgumentException('Invalid JSON data');
		}

		return self::fromArray($data);
	}
}