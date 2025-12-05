<?php

namespace TotalCMS\Domain\Schema\Service;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Service.
 */
readonly class SchemaFactory
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
	 */
	public function generateSchema(array $schemaData): SchemaData
	{
		/** @var SchemaData $schema */
		$schema = $this->serializer->denormalize($schemaData, SchemaData::class);

		return $schema;
	}

	/**
	 * create a schema object.
	 *
	 * @throws \UnexpectedValueException
	 */
	public function generateSchemaFromJson(string $schemaJson): SchemaData
	{
		return $this->serializer->deserialize($schemaJson, SchemaData::class, 'json');
	}
}
