<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;

final class DeckCompatibilityCheckerTest extends TestCase
{
	private DeckCompatibilityChecker $checker;

	protected function setUp(): void
	{
		$this->checker = new DeckCompatibilityChecker();
	}

	public function testIsCompatibleReturnsTrueForEmptySchema(): void
	{
		$schema = [];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsTrueForSchemaWithNoProperties(): void
	{
		$schema = [
			'type'        => 'object',
			'description' => 'A schema without properties',
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsTrueForCompatibleSchema(): void
	{
		$schema = [
			'properties' => [
				'title'  => ['type' => 'string'],
				'count'  => ['type' => 'number'],
				'active' => ['type' => 'boolean'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsTrueForImageType(): void
	{
		// Phase 2 added nested-upload support for `image` inside cards/decks.
		$schema = [
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['type' => 'image'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsFalseForGalleryType(): void
	{
		$schema = [
			'properties' => [
				'photos' => ['type' => 'gallery'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsTrueForFileType(): void
	{
		// Phase 2 added nested-upload support for `file`.
		$schema = [
			'properties' => [
				'document' => ['type' => 'file'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsFalseForDepotType(): void
	{
		$schema = [
			'properties' => [
				'files' => ['type' => 'depot'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsFalseForNestedDeckType(): void
	{
		$schema = [
			'properties' => [
				'nested' => ['type' => 'deck'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsTrueForImageRef(): void
	{
		$schema = [
			'properties' => [
				'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsFalseForGalleryRef(): void
	{
		$schema = [
			'properties' => [
				'photos' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsTrueForFileRef(): void
	{
		$schema = [
			'properties' => [
				'document' => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsFalseForDepotRef(): void
	{
		$schema = [
			'properties' => [
				'files' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleReturnsFalseForDeckRef(): void
	{
		$schema = [
			'properties' => [
				'items' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleChecksNestedProperties(): void
	{
		$schema = [
			'properties' => [
				'metadata' => [
					'properties' => [
						'gallery' => ['type' => 'gallery'], // still incompatible
					],
				],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleChecksArrayItems(): void
	{
		$schema = [
			'properties' => [
				'items' => [
					'items' => ['type' => 'depot'], // still incompatible
				],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testGetIncompatiblePropertiesReturnsEmptyForCompatibleSchema(): void
	{
		$schema = [
			'properties' => [
				'title' => ['type' => 'string'],
				'count' => ['type' => 'number'],
			],
		];

		$result = $this->checker->getIncompatibleProperties($schema);

		$this->assertEmpty($result);
	}

	public function testGetIncompatiblePropertiesReturnsPropertyNames(): void
	{
		$schema = [
			'properties' => [
				'title'   => ['type' => 'string'],
				'photo'   => ['type' => 'image'],   // compatible (Phase 2)
				'gallery' => ['type' => 'gallery'], // still incompatible
				'files'   => ['type' => 'depot'],   // still incompatible
			],
		];

		$result = $this->checker->getIncompatibleProperties($schema);

		$this->assertContains('gallery', $result);
		$this->assertContains('files', $result);
		$this->assertNotContains('photo', $result);
		$this->assertNotContains('title', $result);
	}

	public function testGetIncompatiblePropertiesReturnsEmptyForEmptySchema(): void
	{
		$result = $this->checker->getIncompatibleProperties([]);

		$this->assertEmpty($result);
	}

	public function testGetIncompatibleTypesReturnsExpectedTypes(): void
	{
		$types = $this->checker->getIncompatibleTypes();

		$this->assertContains('gallery', $types);
		$this->assertContains('depot', $types);
		$this->assertContains('deck', $types);
		$this->assertNotContains('image', $types); // Phase 2 made these compatible
		$this->assertNotContains('file', $types);
	}

	public function testGetSchemaIncompatibleTypesReturnsFoundTypes(): void
	{
		$schema = [
			'properties' => [
				'photo'   => ['type' => 'image'],   // compatible
				'gallery' => ['type' => 'gallery'], // incompatible
				'depot'   => ['type' => 'depot'],   // incompatible
				'title'   => ['type' => 'string'],  // compatible
			],
		];

		$result = $this->checker->getSchemaIncompatibleTypes($schema);

		$this->assertContains('gallery', $result);
		$this->assertContains('depot', $result);
		$this->assertNotContains('image', $result);
		$this->assertCount(2, $result);
	}

	public function testGetSchemaIncompatibleTypesHandlesRefTypes(): void
	{
		$schema = [
			'properties' => [
				'gallery' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'],
			],
		];

		$result = $this->checker->getSchemaIncompatibleTypes($schema);

		$this->assertContains('gallery', $result);
	}

	public function testGetSchemaIncompatibleTypesReturnsUniqueTypes(): void
	{
		$schema = [
			'properties' => [
				'gallery1' => ['type' => 'gallery'],
				'gallery2' => ['type' => 'gallery'],
			],
		];

		$result = $this->checker->getSchemaIncompatibleTypes($schema);

		$this->assertCount(1, $result);
		$this->assertContains('gallery', $result);
	}

	public function testGetSchemaIncompatibleTypesReturnsEmptyForEmptySchema(): void
	{
		$result = $this->checker->getSchemaIncompatibleTypes([]);

		$this->assertEmpty($result);
	}

	public function testIsDeckSchemaCompatibleReturnsFalseWithoutSchemaFetcher(): void
	{
		// Checker initialized without SchemaFetcher
		$this->assertFalse($this->checker->isDeckSchemaCompatible('some-schema'));
	}

	public function testGetDeckSchemaIncompatiblePropertiesReturnsEmptyWithoutSchemaFetcher(): void
	{
		// Checker initialized without SchemaFetcher
		$result = $this->checker->getDeckSchemaIncompatibleProperties('some-schema');

		$this->assertEmpty($result);
	}

	public function testIsCompatibleHandlesNonArrayProperties(): void
	{
		$schema = [
			'properties' => [
				'title' => 'string', // Non-array value
				'count' => ['type' => 'number'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	// --- Card composition rules ---
	//
	// The same compatibility checker is used to validate both deck item schemas
	// and card sub-schemas. The rules:
	//
	//   card-in-card  → allowed (nested config object grouping)
	//   card-in-deck  → allowed (deck items can hold card config)
	//   deck-in-card  → blocked (deck.json is in INCOMPATIBLE_REFS)
	//   deck-in-deck  → blocked (existing behavior)

	public function testIsCompatibleAllowsCardPropertyByType(): void
	{
		$schema = [
			'properties' => [
				'sitemap' => ['type' => 'card'],
				'title'   => ['type' => 'string'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleAllowsCardPropertyByRef(): void
	{
		$schema = [
			'properties' => [
				'sitemap' => [
					'$ref'      => 'https://www.totalcms.co/schemas/properties/card.json',
					'schemaref' => 'https://www.totalcms.co/schemas/sitemap-settings.json',
				],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleBlocksDeckPropertyByType(): void
	{
		$schema = [
			'properties' => [
				'features' => ['type' => 'deck'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}

	public function testIsCompatibleBlocksDeckPropertyByRef(): void
	{
		$schema = [
			'properties' => [
				'features' => [
					'$ref'      => 'https://www.totalcms.co/schemas/properties/deck.json',
					'schemaref' => 'https://www.totalcms.co/schemas/feature.json',
				],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
	}
}
