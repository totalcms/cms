<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Storage\SerializerFactory;

/**
 * Service.
 */
readonly class SchemaFactory
{
	/**
	 * create a schema object.
	 *
	 * @param array<string,mixed> $schemaData
	 *
	 * @throws \InvalidArgumentException When the data cannot be mapped onto SchemaData
	 */
	public function generateSchema(array $schemaData): SchemaData
	{
		try {
			/** @var SchemaData $schema */
			$schema = SerializerFactory::json()->denormalize($schemaData, SchemaData::class);
		} catch (\Throwable $e) {
			// A shape the serializer cannot map (wrong-typed field, etc.) is bad
			// input, not a server fault — importers turn this into a 400.
			throw new \InvalidArgumentException('Invalid schema data: ' . $e->getMessage(), 0, $e);
		}

		return $schema;
	}

	/**
	 * create a schema object.
	 *
	 * @throws \InvalidArgumentException When the JSON is invalid or cannot be mapped onto SchemaData
	 */
	public function generateSchemaFromJson(string $schemaJson): SchemaData
	{
		$schemaData = json_decode($schemaJson, true);
		if (!is_array($schemaData)) {
			throw new \InvalidArgumentException('Invalid schema JSON');
		}

		return $this->generateSchema($schemaData);
	}
}
