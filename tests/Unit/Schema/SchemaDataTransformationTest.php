<?php

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Data\SchemaData;

#[CoversClass(SchemaData::class)]
final class SchemaDataTransformationTest extends TestCase
{
	public function testSchemaDataTransformsDeckSyntaxInToArray(): void
	{
		$properties = [
			'title' => [
				'type'  => 'string',
				'field' => 'text',
			],
			'features' => [
				'field'   => 'deck',
				'label'   => 'Product Features',
				'help'    => 'Add key features and selling points for this product',
				'deckref' => 'https://www.totalcms.co/schemas/custom/features.json',
				'$ref'    => 'https://www.totalcms.co/schemas/properties/deck.json',
			],
		];

		$schema              = new SchemaData();
		$schema->id          = 'testschema';
		$schema->description = 'Test schema with deck transformation';
		$schema->properties  = $properties;
		$schema->required    = ['title'];
		$schema->index       = ['title'];

		$array = $schema->toArray();

		// Check that the deck property was transformed
		$featuresProperty = $array['properties']['features'];

		$this->assertArrayHasKey('patternProperties', $featuresProperty);
		$this->assertArrayHasKey('deckref', $featuresProperty);

		$expected = [
			'^[a-zA-Z]\\w*$' => [
				'$ref' => 'https://www.totalcms.co/schemas/custom/features.json',
			],
		];

		$this->assertEquals($expected, $featuresProperty['patternProperties']);

		// Other properties should remain unchanged
		$this->assertEquals(['type' => 'string', 'field' => 'text'], $array['properties']['title']);
	}

	public function testSchemaDataWithoutDeckPropertiesRemainsUnchanged(): void
	{
		$properties = [
			'title' => [
				'type'  => 'string',
				'field' => 'text',
			],
			'count' => [
				'type'  => 'number',
				'field' => 'number',
			],
		];

		$schema              = new SchemaData();
		$schema->id          = 'testschema';
		$schema->description = 'Test schema without deck properties';
		$schema->properties  = $properties;
		$schema->required    = ['title'];
		$schema->index       = ['title'];

		$array = $schema->toArray();

		// Properties should remain exactly the same
		$this->assertEquals($properties, $array['properties']);
	}

	public function testSchemaDataTransformsMultipleDeckProperties(): void
	{
		$properties = [
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
		];

		$schema              = new SchemaData();
		$schema->id          = 'testschema';
		$schema->description = 'Test schema with multiple deck properties';
		$schema->properties  = $properties;
		$schema->required    = [];
		$schema->index       = [];

		$array = $schema->toArray();

		// Both deck properties should be transformed
		$this->assertArrayHasKey('patternProperties', $array['properties']['features']);
		$this->assertArrayHasKey('patternProperties', $array['properties']['updates']);
		$this->assertArrayHasKey('deckref', $array['properties']['features']);
		$this->assertArrayHasKey('deckref', $array['properties']['updates']);

		// Check the correct schema references are preserved
		$this->assertEquals(
			'https://www.totalcms.co/schemas/custom/features.json',
			$array['properties']['features']['patternProperties']['^[a-zA-Z]\\w*$']['$ref']
		);
		$this->assertEquals(
			'https://www.totalcms.co/schemas/custom/updates.json',
			$array['properties']['updates']['patternProperties']['^[a-zA-Z]\\w*$']['$ref']
		);
	}

	/**
	 * Canonical-path coverage: same transform behavior when properties use the new
	 * `schemaref` key. Verifies that a freshly authored schema works without depending
	 * on the legacy alias.
	 */
	public function testSchemaDataTransformsDeckSyntaxUsingSchemaRef(): void
	{
		$properties = [
			'features' => [
				'field'     => 'deck',
				'schemaref' => 'https://www.totalcms.co/schemas/custom/features.json',
				'$ref'      => 'https://www.totalcms.co/schemas/properties/deck.json',
			],
		];

		$schema              = new SchemaData();
		$schema->id          = 'testschema';
		$schema->description = 'Schema with canonical schemaref';
		$schema->properties  = $properties;
		$schema->required    = [];
		$schema->index       = [];

		$array = $schema->toArray();

		$featuresProperty = $array['properties']['features'];

		$this->assertArrayHasKey('patternProperties', $featuresProperty);
		$this->assertArrayHasKey('schemaref', $featuresProperty);
		$this->assertEquals(
			'https://www.totalcms.co/schemas/custom/features.json',
			$featuresProperty['patternProperties']['^[a-zA-Z]\\w*$']['$ref']
		);
	}
}
