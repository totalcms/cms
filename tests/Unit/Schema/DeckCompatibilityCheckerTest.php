<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;

#[CoversClass(DeckCompatibilityChecker::class)]
final class DeckCompatibilityCheckerTest extends TestCase
{
	private DeckCompatibilityChecker $checker;

	protected function setUp(): void
	{
		// Use null SchemaFetcher for basic compatibility testing
		$this->checker = new DeckCompatibilityChecker();
	}

	public function testEmptySchemaIsCompatible(): void
	{
		$schema = [];
		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testSchemaWithoutPropertiesIsCompatible(): void
	{
		$schema = [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'type'    => 'object',
		];
		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testCompatibleSchemaWithBasicTypes(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'       => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'active'      => ['type' => 'boolean'],
				'count'       => ['type' => 'number'],
				'created'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json'],
				'email'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/email.json'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testCompatibleSchemaWithImageType(): void
	{
		// Phase 2 added nested-upload support for `image` inside cards (and Phase 3
		// extends to decks), so `image` is now allowed in deck/card schemas.
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['type' => 'image'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithGalleryType(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'  => ['type' => 'string'],
				'photos' => ['type' => 'gallery'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photos'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testCompatibleSchemaWithFileType(): void
	{
		// Same as image — Phase 2 added nested-upload support for `file`.
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'      => ['type' => 'string'],
				'attachment' => ['type' => 'file'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithDepotType(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => ['type' => 'string'],
				'document' => ['type' => 'depot'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['document'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testCompatibleSchemaWithImageRef(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithGalleryRef(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'  => ['type' => 'string'],
				'photos' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photos'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testCompatibleSchemaWithFileRef(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'      => ['type' => 'string'],
				'attachment' => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithDepotRef(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => ['type' => 'string'],
				'document' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['document'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithDeckType(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => ['type' => 'string'],
				'features' => ['type' => 'deck'], // Incompatible - decks within decks not supported
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['features'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithDeckRef(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => ['type' => 'string'],
				'features' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['features'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testMultipleIncompatibleProperties(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'       => ['type' => 'string'],
				'photo'       => ['type' => 'image'], // Compatible (Phase 2)
				'gallery'     => ['type' => 'gallery'], // Incompatible
				'document'    => ['type' => 'file'], // Compatible (Phase 2)
				'archive'     => ['type' => 'depot'], // Incompatible
				'description' => ['type' => 'string'], // Compatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$incompatible = $this->checker->getIncompatibleProperties($schema);
		$this->assertContains('gallery', $incompatible);
		$this->assertContains('archive', $incompatible);
		$this->assertNotContains('photo', $incompatible);
		$this->assertNotContains('document', $incompatible);
		$this->assertNotContains('title', $incompatible);
		$this->assertNotContains('description', $incompatible);
	}

	public function testNestedObjectWithIncompatibleProperty(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => ['type' => 'string'],
				'metadata' => [
					'type'       => 'object',
					'properties' => [
						'name'    => ['type' => 'string'],
						'gallery' => ['type' => 'gallery'], // Incompatible nested property
					],
				],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['metadata'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testArrayWithIncompatibleItems(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'    => ['type' => 'string'],
				'archives' => [
					'type'  => 'array',
					'items' => ['type' => 'depot'], // Incompatible array items
				],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['archives'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testComplexCompatibleSchema(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'       => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'tags'        => [
					'type'  => 'array',
					'items' => ['type' => 'string'],
				],
				'metadata' => [
					'type'       => 'object',
					'properties' => [
						'author'    => ['type' => 'string'],
						'published' => ['type' => 'boolean'],
						'created'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json'],
					],
				],
				'contact' => ['$ref' => 'https://www.totalcms.co/schemas/properties/email.json'],
				'rating'  => ['type' => 'number'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testGetIncompatibleTypes(): void
	{
		$types    = $this->checker->getIncompatibleTypes();
		$expected = ['gallery', 'depot', 'deck'];

		$this->assertSame($expected, $types);
	}

	public function testProductFeatureListUseCase(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'       => ['type' => 'string'],
				'icon'        => ['$ref' => 'https://www.totalcms.co/schemas/properties/svg.json'],
				'description' => ['type' => 'string'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testProductUpdatesUseCase(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'version' => ['type' => 'string'],
				'date'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json'],
				'notes'   => ['type' => 'string'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testHandlesNonArrayProperty(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => 'string', // Non-array property definition
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testHandlesNullProperty(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => null, // Null property definition
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testGetSchemaIncompatibleTypesReturnsEmpty(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'count' => ['type' => 'number'],
			],
		];

		$this->assertSame([], $this->checker->getSchemaIncompatibleTypes($schema));
	}

	public function testGetSchemaIncompatibleTypesFindsDirectTypes(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'   => ['type' => 'string'],
				'photo'   => ['type' => 'image'],   // compatible
				'gallery' => ['type' => 'gallery'], // incompatible
				'archive' => ['type' => 'depot'],   // incompatible
			],
		];

		$incompatibleTypes = $this->checker->getSchemaIncompatibleTypes($schema);
		$this->assertContains('gallery', $incompatibleTypes);
		$this->assertContains('depot', $incompatibleTypes);
		$this->assertNotContains('image', $incompatibleTypes);
		$this->assertCount(2, $incompatibleTypes);
	}

	public function testGetSchemaIncompatibleTypesFindsRefTypes(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'   => ['type' => 'string'],
				'photo'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],   // compatible
				'gallery' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'], // incompatible
				'archive' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json'],   // incompatible
			],
		];

		$incompatibleTypes = $this->checker->getSchemaIncompatibleTypes($schema);
		$this->assertContains('gallery', $incompatibleTypes);
		$this->assertContains('depot', $incompatibleTypes);
		$this->assertNotContains('image', $incompatibleTypes);
		$this->assertCount(2, $incompatibleTypes);
	}

	public function testGetSchemaIncompatibleTypesHandlesEmptySchema(): void
	{
		$schema = [];
		$this->assertSame([], $this->checker->getSchemaIncompatibleTypes($schema));
	}

	public function testGetSchemaIncompatibleTypesHandlesNoProperties(): void
	{
		$schema = ['type' => 'object'];
		$this->assertSame([], $this->checker->getSchemaIncompatibleTypes($schema));
	}

	public function testGetSchemaIncompatibleTypesDeduplicates(): void
	{
		$schema = [
			'type'       => 'object',
			'properties' => [
				'gallery1' => ['type' => 'gallery'],
				'gallery2' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'],
				'gallery3' => ['type' => 'gallery'], // Duplicate type
			],
		];

		$incompatibleTypes = $this->checker->getSchemaIncompatibleTypes($schema);
		$this->assertSame(['gallery'], $incompatibleTypes);
		$this->assertCount(1, $incompatibleTypes);
	}
}
