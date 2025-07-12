<?php

declare(strict_types = 1);

namespace TotalCMS\Domain\JumpStart\Data;

final class JumpStartData
{
	public string $version = '1.0.0';

	/** @var array<string,mixed> */
	public array $collections = [
		'reserved' => [],
		'custom'   => [],
	];
	/** @var array<int,array<string,mixed>> */
	public array $schemas = [];
	/** @var array<int,array<string,mixed>> */
	public array $objects = [];
	/** @var array<int,array<string,mixed>> */
	public array $factory = [];

	public function __construct(
		public string $name        = 'Exported from Current CMS Data',
		public string $description = 'Jumpstart definition generated from existing Total CMS data',
	) {
		$this->description .= ' - ' . date('Y-m-d H:i:s');
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}

	public function setDescription(string $description): void
	{
		$this->description = $description;
	}

	/** @param array<string,mixed> $schema */
	public function addSchema(array $schema): void
	{
		$this->schemas[] = $schema;
	}

	public function addReservedCollection(string $collectionType): void
	{
		if (!in_array($collectionType, $this->collections['reserved'])) {
			$this->collections['reserved'][] = $collectionType;
		}
	}

	/** @param array<string,mixed> $collection */
	public function addCustomCollection(array $collection): void
	{
		$this->collections['custom'][] = $collection;
	}

	/** @param array<string,mixed> $object */
	public function addObject(array $object): void
	{
		$this->objects[] = $object;
	}

	/** @param array<string, mixed> $factoryDef */
	public function addFactory(array $factoryDef): void
	{
		$this->factory[] = $factoryDef;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'version'     => $this->version,
			'name'        => $this->name,
			'description' => $this->description,
			'schemas'     => $this->schemas,
			'collections' => $this->collections,
			'objects'     => $this->objects,
			'factory'     => $this->factory,
		];
	}

	/** @param array<string,mixed> $data */
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

	public function toJson(): string
	{
		$json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode jumpstart data to JSON');
		}

		return $json;
	}

	public static function fromJson(string $json): self
	{
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new \InvalidArgumentException('Invalid JSON data');
		}

		return self::fromArray($data);
	}

	public function isEmpty(): bool
	{
		return empty($this->collections['reserved'])
			&& empty($this->collections['custom'])
			&& empty($this->schemas)
			&& empty($this->objects)
			&& empty($this->factory);
	}

	public function getTotalObjectCount(): int
	{
		$count = count($this->objects);

		foreach ($this->factory as $factoryDef) {
			if (isset($factoryDef['id'])) {
				// Factory with specific ID counts as 1 object
				$count++;
			} else {
				// Factory with count creates multiple objects
				$count += $factoryDef['count'] ?? 0;
			}
		}

		return $count;
	}
}
