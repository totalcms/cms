<?php

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Service\SchemaTransformer;

#[CoversClass(SchemaTransformer::class)]
final class SchemaTransformerTest extends TestCase
{
	private SchemaTransformer $transformer;

	protected function setUp(): void
	{
		$this->transformer = new SchemaTransformer();
	}

	public function testTransformSchemaWithoutDeckPropertiesRemainsUnchanged(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'count' => ['type' => 'number'],
			],
		];

		$result = $this->transformer->transformSchema($schema);
		$this->assertSame($schema, $result);
	}

	public function testTransformSchemaWithoutPropertiesRemainsUnchanged(): void
	{
		$schema = ['type' => 'object'];
		$result = $this->transformer->transformSchema($schema);
		$this->assertSame($schema, $result);
	}

	public function testTransformsDeckPropertyWithSimplifiedSyntax(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'features' => [
					'field'   => 'deck',
					'label'   => 'Product Features',
					'help'    => 'Add key features and selling points for this product',
					'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$expectedFeatures = [
			'field'             => 'deck',
			'label'             => 'Product Features',
			'help'              => 'Add key features and selling points for this product',
			'patternProperties' => [
				'^[a-zA-Z]\\w*$' => [
					'$ref' => 'https://www.totalcms.co/schemas/custom/features.json',
				],
			],
			'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
			'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
		];

		$this->assertEquals($expectedFeatures, $result['properties']['features']);
		$this->assertArrayHasKey('deckref', $result['properties']['features']);
	}

	public function testIgnoresNonDeckPropertiesWithDeckrefKey(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'notadeck' => [
					'field'   => 'text',
					'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/text.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);
		// Should remain unchanged since it's not a deck property
		$this->assertSame($schema, $result);
	}

	public function testTransformsMultipleDeckProperties(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'features' => [
					'field'   => 'deck',
					'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
				'updates' => [
					'field'   => 'deck',
					'deckref' => 'https://www.totalcms.co/schemas/custom/updates.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
				'title' => [
					'type' => 'string',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// Both deck properties should be transformed
		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);
		$this->assertArrayHasKey('patternProperties', $result['properties']['updates']);
		$this->assertArrayHasKey('deckref', $result['properties']['features']);
		$this->assertArrayHasKey('deckref', $result['properties']['updates']);

		// Non-deck property should remain unchanged
		$this->assertEquals(['type' => 'string'], $result['properties']['title']);
	}

	public function testHandlesNonArrayProperties(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'invalid'  => 'not_an_array',
				'features' => [
					'field'   => 'deck',
					'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// Invalid property should remain unchanged
		$this->assertEquals('not_an_array', $result['properties']['invalid']);
		// Valid deck property should be transformed
		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);
	}

	public function testDeckPropertyWithoutDeckrefKeyRemainsUnchanged(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'features' => [
					'field' => 'deck',
					'label' => 'Product Features',
					'$ref'  => 'https://www.totalcms.co/schemas/properties/deck.json',
					// No 'deckref' or 'schemaref' key - should use existing patternProperties if any
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);
		$this->assertSame($schema, $result);
	}

	/**
	 * Canonical-path coverage: same expansion behavior when the schema uses the new
	 * `schemaref` key instead of the legacy `deckref`.
	 */
	public function testTransformsDeckPropertyUsingCanonicalSchemaRef(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'features' => [
					'field'     => 'deck',
					'schemaref' => 'https://www.totalcms.co/schemas/custom/features.json',
					'$ref'      => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);
		$this->assertSame(
			'https://www.totalcms.co/schemas/custom/features.json',
			$result['properties']['features']['patternProperties']['^[a-zA-Z]\\w*$']['$ref'],
		);
		// schemaref should be preserved on the property for form building
		$this->assertArrayHasKey('schemaref', $result['properties']['features']);
		$this->assertSame(
			'https://www.totalcms.co/schemas/custom/features.json',
			$result['properties']['features']['schemaref'],
		);
	}
}
