<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Service\SchemaTransformer;

/**
 * Tests for the card-property expansion path of SchemaTransformer.
 *
 * Cards point their `$ref` at the schemaref'd sub-schema directly, so
 * JSON Schema validation runs against the actual card shape rather than
 * the generic card.json wrapper.
 */
#[CoversClass(SchemaTransformer::class)]
final class SchemaTransformerCardTest extends TestCase
{
	private SchemaTransformer $transformer;

	protected function setUp(): void
	{
		$this->transformer = new SchemaTransformer();
	}

	public function testCardWithSchemaRefRewritesRefToSubSchema(): void
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

		// $ref is rewritten to the sub-schema (so JSON Schema validates against the card's shape)
		$this->assertSame(
			'https://www.totalcms.co/schemas/sitemap-settings.json',
			$result['properties']['sitemap']['$ref']
		);
		// schemaref is preserved on the property for form building
		$this->assertArrayHasKey('schemaref', $result['properties']['sitemap']);
	}

	public function testCardWithLegacyDeckrefAliasIsAlsoExpanded(): void
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
			'https://www.totalcms.co/schemas/sitemap-settings.json',
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

	public function testNonCardPropertyWithSchemaRefIgnoredByCardExpansion(): void
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

		// $ref unchanged — card expansion only applies to card.json properties
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

		// Card → $ref rewrite
		$this->assertSame(
			'https://www.totalcms.co/schemas/sitemap-settings.json',
			$result['properties']['sitemap']['$ref']
		);
	}
}
