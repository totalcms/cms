<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Service\SchemaTransformer;

/**
 * Tests for the card-property handling in SchemaTransformer.
 *
 * Cards keep `$ref` pointed at the canonical `properties/card.json`. The
 * sub-schema lives in `schemaref`, which is metadata for form rendering;
 * card.json itself permits the nested object's shape.
 */
#[CoversClass(SchemaTransformer::class)]
final class SchemaTransformerCardTest extends TestCase
{
	private SchemaTransformer $transformer;

	protected function setUp(): void
	{
		$this->transformer = new SchemaTransformer();
	}

	public function testCardWithSchemaRefLeavesRefUntouched(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'sitemap' => [
					'field'     => 'card',
					'schemaref' => 'https://www.totalcms.co/schemas/sitemap-settings.json',
					'$ref'      => 'https://www.totalcms.co/schemas/properties/card.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// $ref stays canonical — type info is preserved for the form editor.
		$this->assertSame(
			'https://www.totalcms.co/schemas/properties/card.json',
			$result['properties']['sitemap']['$ref']
		);
		// schemaref is preserved on the property for form building.
		$this->assertSame(
			'https://www.totalcms.co/schemas/sitemap-settings.json',
			$result['properties']['sitemap']['schemaref']
		);
	}

	public function testCardWithLegacyDeckrefAliasIsLeftUntouched(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'sitemap' => [
					'field'   => 'card',
					'deckref' => 'https://www.totalcms.co/schemas/sitemap-settings.json',
					'$ref'    => 'https://www.totalcms.co/schemas/properties/card.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertSame(
			'https://www.totalcms.co/schemas/properties/card.json',
			$result['properties']['sitemap']['$ref']
		);
	}

	public function testCardWithoutSchemaRefRemainsUnchanged(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'sitemap' => [
					'field' => 'card',
					'$ref'  => 'https://www.totalcms.co/schemas/properties/card.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertSame($schema, $result);
	}

	public function testNonCardPropertyWithSchemaRefLeftAlone(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'field'     => 'text',
					'schemaref' => 'https://www.totalcms.co/schemas/whatever.json',
					'$ref'      => 'https://www.totalcms.co/schemas/properties/text.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		$this->assertSame(
			'https://www.totalcms.co/schemas/properties/text.json',
			$result['properties']['title']['$ref']
		);
	}

	public function testCardAndDeckCanCoexistInSameSchema(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'features' => [
					'field'     => 'deck',
					'schemaref' => 'https://www.totalcms.co/schemas/feature.json',
					'$ref'      => 'https://www.totalcms.co/schemas/properties/deck.json',
				],
				'sitemap' => [
					'field'     => 'card',
					'schemaref' => 'https://www.totalcms.co/schemas/sitemap-settings.json',
					'$ref'      => 'https://www.totalcms.co/schemas/properties/card.json',
				],
			],
		];

		$result = $this->transformer->transformSchema($schema);

		// Deck → patternProperties expansion
		$this->assertArrayHasKey('patternProperties', $result['properties']['features']);

		// Card → $ref unchanged
		$this->assertSame(
			'https://www.totalcms.co/schemas/properties/card.json',
			$result['properties']['sitemap']['$ref']
		);
	}
}
