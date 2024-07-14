<?php

namespace TotalCMS\Domain\Schema\Service;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Service.
 */
final class SchemaFactory
{
	private Serializer $serializer;

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/**
	 * create a schema object.
	 *
	 * @param array<string,mixed> $schemaData
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return SchemaData
	 */
	public function generateSchema(array $schemaData): SchemaData
	{
		$schema = $this->serializer->denormalize($schemaData, SchemaData::class);

		if (!$schema instanceof SchemaData) {
			throw new \UnexpectedValueException('Invalid Schema data provided');
		}

		return $schema;
	}

	/**
	 * create a schema object.
	 *
	 * @param string $schemaJson
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return SchemaData
	 */
	public function generateSchemaFromJson(string $schemaJson): SchemaData
	{
		$schema = $this->serializer->deserialize($schemaJson, SchemaData::class, 'json');

		if (!$schema instanceof SchemaData) {
			throw new \UnexpectedValueException('Invalid Schema json provided');
		}

		return $schema;
	}
}
