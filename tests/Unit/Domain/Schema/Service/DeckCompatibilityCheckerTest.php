<?php

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

	public function testIsCompatibleReturnsFalseForImageType(): void
	{
		$schema = [
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['type' => 'image'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
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

	public function testIsCompatibleReturnsFalseForFileType(): void
	{
		$schema = [
			'properties' => [
				'document' => ['type' => 'file'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
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

	public function testIsCompatibleReturnsFalseForImageRef(): void
	{
		$schema = [
			'properties' => [
				'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
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

	public function testIsCompatibleReturnsFalseForFileRef(): void
	{
		$schema = [
			'properties' => [
				'document' => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
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
						'photo' => ['type' => 'image'],
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
					'items' => ['type' => 'image'],
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
				'title' => ['type' => 'string'],
				'photo' => ['type' => 'image'],
				'files' => ['type' => 'depot'],
			],
		];

		$result = $this->checker->getIncompatibleProperties($schema);

		$this->assertContains('photo', $result);
		$this->assertContains('files', $result);
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

		$this->assertContains('image', $types);
		$this->assertContains('gallery', $types);
		$this->assertContains('file', $types);
		$this->assertContains('depot', $types);
		$this->assertContains('deck', $types);
	}

	public function testGetSchemaIncompatibleTypesReturnsFoundTypes(): void
	{
		$schema = [
			'properties' => [
				'photo'    => ['type' => 'image'],
				'document' => ['type' => 'file'],
				'title'    => ['type' => 'string'],
			],
		];

		$result = $this->checker->getSchemaIncompatibleTypes($schema);

		$this->assertContains('image', $result);
		$this->assertContains('file', $result);
		$this->assertCount(2, $result);
	}

	public function testGetSchemaIncompatibleTypesHandlesRefTypes(): void
	{
		$schema = [
			'properties' => [
				'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
			],
		];

		$result = $this->checker->getSchemaIncompatibleTypes($schema);

		$this->assertContains('image', $result);
	}

	public function testGetSchemaIncompatibleTypesReturnsUniqueTypes(): void
	{
		$schema = [
			'properties' => [
				'photo1' => ['type' => 'image'],
				'photo2' => ['type' => 'image'],
			],
		];

		$result = $this->checker->getSchemaIncompatibleTypes($schema);

		$this->assertCount(1, $result);
		$this->assertContains('image', $result);
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
}
