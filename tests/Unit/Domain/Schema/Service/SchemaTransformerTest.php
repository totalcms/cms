<?php

namespace Tests\Unit\Domain\Schema\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Service\SchemaTransformer;

final class SchemaTransformerTest extends TestCase
{
	private SchemaTransformer $transformer;

	protected function setUp(): void
	{
		$this->transformer = new SchemaTransformer();
	}

	public function testTransformSchemaReturnsUnmodifiedSchemaWithoutProperties(): void
	{
		$schema = [
			'type'        => 'object',
			'description' => 'A schema without properties',
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertSame($schema, $result);
	}

	public function testTransformSchemaReturnsUnmodifiedSchemaWithEmptyProperties(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertSame($schema, $result);
	}

	public function testTransformSchemaExpandsDeckPropertyWithDeckref(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'features' => [
					'field'   => 'deck',
					'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);
		$this->assertSame(
			['$ref' => 'https://www.totalcms.co/schemas/custom/features.json'],
			$result['properties']['features']['patternProperties']['^[a-zA-Z]\\w*$']
		);
	}

	public function testTransformSchemaPreservesDeckrefProperty(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'items' => [
					'field'   => 'deck',
					'deckref' => 'https://example.com/item.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// deckref should be preserved for form building
		$this->assertArrayHasKey('deckref', $result['properties']['items']);
		$this->assertSame('https://example.com/item.json', $result['properties']['items']['deckref']);
	}

	public function testTransformSchemaDoesNotExpandNonDeckProperties(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'field' => 'text',
					'type'  => 'string',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertArrayNotHasKey('patternProperties', $result['properties']['title']);
		$this->assertSame($schema['properties']['title'], $result['properties']['title']);
	}

	public function testTransformSchemaDoesNotExpandDeckWithoutDeckref(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'items' => [
					'field' => 'deck',
					'$ref'  => 'https://www.totalcms.co/schemas/properties/deck.json',
					// No deckref property
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertArrayNotHasKey('patternProperties', $result['properties']['items']);
	}

	public function testTransformSchemaHandlesMixedProperties(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'field' => 'text',
					'type'  => 'string',
				],
				'features' => [
					'field'   => 'deck',
					'deckref' => 'https://example.com/feature.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
				'count' => [
					'field' => 'number',
					'type'  => 'integer',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// title should be unchanged
		$this->assertSame($schema['properties']['title'], $result['properties']['title']);

		// features should have patternProperties
		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);

		// count should be unchanged
		$this->assertSame($schema['properties']['count'], $result['properties']['count']);
	}

	public function testTransformSchemaPreservesOtherPropertyAttributes(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'items' => [
					'field'       => 'deck',
					'deckref'     => 'https://example.com/item.json',
					'$ref'        => 'https://www.totalcms.co/schemas/properties/deck.json',
					'label'       => 'Items',
					'description' => 'A list of items',
					'minItems'    => 1,
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// All original properties should be preserved
		$this->assertSame('deck', $result['properties']['items']['field']);
		$this->assertSame('Items', $result['properties']['items']['label']);
		$this->assertSame('A list of items', $result['properties']['items']['description']);
		$this->assertSame(1, $result['properties']['items']['minItems']);
	}

	public function testTransformSchemaHandlesNonArrayPropertyValues(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => 'string',  // Non-array value
				'features' => [
					'field'   => 'deck',
					'deckref' => 'https://example.com/feature.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// Non-array property should be preserved
		$this->assertSame('string', $result['properties']['title']);

		// Deck property should still be transformed
		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);
	}
}
