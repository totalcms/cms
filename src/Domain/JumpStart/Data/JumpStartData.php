<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Data;

class JumpStartData
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
	/** @var array<int,array<string,string>> */
	public array $templates = [];

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

	/** @param array<string, string> $template */
	public function addTemplate(array $template): void
	{
		$this->templates[] = $template;
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
			'templates'   => $this->templates,
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
		$jumpstart->templates   = $data['templates'] ?? [];

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

	/**
	 * Helper method to encode JSON with error checking.
	 *
	 * @param mixed $data
	 */
	private function encodeJson($data, int $flags = 0): string
	{
		$json = json_encode($data, $flags);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode data to JSON: ' . json_last_error_msg());
		}

		return $json;
	}

	/**
	 * Stream JSON output to a file to avoid memory issues with large datasets.
	 *
	 * @param resource $fileHandle File handle to write to
	 */
	public function streamJsonToFile($fileHandle): void
	{
		// Start JSON object
		fwrite($fileHandle, "{\n");

		// Write metadata
		fwrite($fileHandle, '  "version": ' . $this->encodeJson($this->version) . ",\n");
		fwrite($fileHandle, '  "name": ' . $this->encodeJson($this->name) . ",\n");
		fwrite($fileHandle, '  "description": ' . $this->encodeJson($this->description) . ",\n");

		// Write schemas array
		fwrite($fileHandle, '  "schemas": ');
		fwrite($fileHandle, $this->encodeJson($this->schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		fwrite($fileHandle, ",\n");

		// Write collections
		fwrite($fileHandle, '  "collections": ');
		fwrite($fileHandle, $this->encodeJson($this->collections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		fwrite($fileHandle, ",\n");

		// Write objects array - stream each object individually to save memory
		fwrite($fileHandle, '  "objects": [');
		$first = true;
		foreach ($this->objects as $object) {
			if (!$first) {
				fwrite($fileHandle, ',');
			}
			fwrite($fileHandle, "\n    ");
			fwrite($fileHandle, $this->encodeJson($object, JSON_UNESCAPED_SLASHES));
			$first = false;

			// Clear the object from memory after writing
			unset($object);
		}
		fwrite($fileHandle, "\n  ],\n");

		// Write factory array
		fwrite($fileHandle, '  "factory": ');
		fwrite($fileHandle, $this->encodeJson($this->factory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		fwrite($fileHandle, ",\n");

		// Write templates array
		fwrite($fileHandle, '  "templates": ');
		fwrite($fileHandle, $this->encodeJson($this->templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		fwrite($fileHandle, "\n");

		// End JSON object
		fwrite($fileHandle, "}\n");
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
			&& $this->schemas === []
			&& $this->objects === []
			&& $this->factory === []
			&& $this->templates === [];
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
