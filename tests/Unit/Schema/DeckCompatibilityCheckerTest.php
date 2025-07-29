<?php

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
			'type' => 'object',
		];
		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testCompatibleSchemaWithBasicTypes(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'active' => ['type' => 'boolean'],
				'count' => ['type' => 'number'],
				'created' => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json'],
				'email' => ['$ref' => 'https://www.totalcms.co/schemas/properties/email.json'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithImageType(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['type' => 'image'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photo'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithGalleryType(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photos' => ['type' => 'gallery'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photos'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithFileType(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'attachment' => ['type' => 'file'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['attachment'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithDepotType(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'document' => ['type' => 'depot'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['document'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithImageRef(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photo'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithGalleryRef(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photos' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photos'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithFileRef(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'attachment' => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['attachment'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testIncompatibleSchemaWithDepotRef(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'document' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json'], // Incompatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['document'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testMultipleIncompatibleProperties(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photo' => ['type' => 'image'], // Incompatible
				'gallery' => ['type' => 'gallery'], // Incompatible
				'document' => ['type' => 'file'], // Incompatible
				'description' => ['type' => 'string'], // Compatible
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$incompatible = $this->checker->getIncompatibleProperties($schema);
		$this->assertContains('photo', $incompatible);
		$this->assertContains('gallery', $incompatible);
		$this->assertContains('document', $incompatible);
		$this->assertNotContains('title', $incompatible);
		$this->assertNotContains('description', $incompatible);
	}

	public function testNestedObjectWithIncompatibleProperty(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'metadata' => [
					'type' => 'object',
					'properties' => [
						'name' => ['type' => 'string'],
						'photo' => ['type' => 'image'], // Incompatible nested property
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
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'photos' => [
					'type' => 'array',
					'items' => ['type' => 'image'], // Incompatible array items
				],
			],
		];

		$this->assertFalse($this->checker->isCompatible($schema));
		$this->assertSame(['photos'], $this->checker->getIncompatibleProperties($schema));
	}

	public function testComplexCompatibleSchema(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
				],
				'metadata' => [
					'type' => 'object',
					'properties' => [
						'author' => ['type' => 'string'],
						'published' => ['type' => 'boolean'],
						'created' => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json'],
					],
				],
				'contact' => ['$ref' => 'https://www.totalcms.co/schemas/properties/email.json'],
				'rating' => ['type' => 'number'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testGetIncompatibleTypes(): void
	{
		$types = $this->checker->getIncompatibleTypes();
		$expected = ['image', 'gallery', 'file', 'depot'];
		
		$this->assertSame($expected, $types);
	}

	public function testProductFeatureListUseCase(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'icon' => ['$ref' => 'https://www.totalcms.co/schemas/properties/svg.json'],
				'description' => ['type' => 'string'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testProductUpdatesUseCase(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'version' => ['type' => 'string'],
				'date' => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json'],
				'notes' => ['type' => 'string'],
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
		$this->assertEmpty($this->checker->getIncompatibleProperties($schema));
	}

	public function testHandlesNonArrayProperty(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => 'string', // Non-array property definition
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}

	public function testHandlesNullProperty(): void
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'title' => null, // Null property definition
			],
		];

		$this->assertTrue($this->checker->isCompatible($schema));
	}
}