<?php

namespace Tests\Unit\JumpStart;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;

final class JumpStartDataTest extends TestCase
{
	public function testCreatesWithDefaultValues(): void
	{
		$jumpStart = new JumpStartData();

		$this->assertSame('1.0.0', $jumpStart->version);
		$this->assertSame('Exported from Current CMS Data', $jumpStart->name);
		$this->assertStringContainsString('Jumpstart definition generated from existing Total CMS data', $jumpStart->description);
		$this->assertStringContainsString(date('Y-m-d'), $jumpStart->description);

		$this->assertIsArray($jumpStart->collections);
		$this->assertArrayHasKey('reserved', $jumpStart->collections);
		$this->assertArrayHasKey('custom', $jumpStart->collections);
		$this->assertEmpty($jumpStart->collections['reserved']);
		$this->assertEmpty($jumpStart->collections['custom']);

		$this->assertIsArray($jumpStart->schemas);
		$this->assertEmpty($jumpStart->schemas);

		$this->assertIsArray($jumpStart->objects);
		$this->assertEmpty($jumpStart->objects);

		$this->assertIsArray($jumpStart->factory);
		$this->assertEmpty($jumpStart->factory);
	}

	public function testCreatesWithCustomMetadata(): void
	{
		$customName        = 'My Custom JumpStart';
		$customDescription = 'Custom description for testing';

		$jumpStart = new JumpStartData($customName, $customDescription);

		$this->assertSame($customName, $jumpStart->name);
		$this->assertStringContainsString($customDescription, $jumpStart->description);
		$this->assertStringContainsString(date('Y-m-d H:i:s'), $jumpStart->description);
	}

	public function testSetName(): void
	{
		$jumpStart = new JumpStartData();
		$newName   = 'Updated Name';

		$jumpStart->setName($newName);

		$this->assertSame($newName, $jumpStart->name);
	}

	public function testSetDescription(): void
	{
		$jumpStart      = new JumpStartData();
		$newDescription = 'Updated description';

		$jumpStart->setDescription($newDescription);

		$this->assertSame($newDescription, $jumpStart->description);
	}

	public function testAddReservedCollection(): void
	{
		$jumpStart   = new JumpStartData();
		$collections = ['blog', 'gallery', 'image'];

		foreach ($collections as $collection) {
			$jumpStart->addReservedCollection($collection);
		}

		$this->assertCount(3, $jumpStart->collections['reserved']);
		$this->assertContains('blog', $jumpStart->collections['reserved']);
		$this->assertContains('gallery', $jumpStart->collections['reserved']);
		$this->assertContains('image', $jumpStart->collections['reserved']);
	}

	public function testAddCustomCollection(): void
	{
		$jumpStart        = new JumpStartData();
		$customCollection = [
			'id'       => 'custom-blog',
			'name'     => 'Custom Blog',
			'schema'   => 'blog',
			'settings' => ['featured' => true],
		];

		$jumpStart->addCustomCollection($customCollection);

		$this->assertCount(1, $jumpStart->collections['custom']);
		$this->assertSame($customCollection, $jumpStart->collections['custom'][0]);
	}

	public function testAddSchema(): void
	{
		$jumpStart = new JumpStartData();
		$schema    = [
			'id'         => 'custom-schema',
			'name'       => 'Custom Schema',
			'type'       => 'object',
			'properties' => [
				'title' => ['type' => 'string', 'field' => 'text'],
			],
		];

		$jumpStart->addSchema($schema);

		$this->assertCount(1, $jumpStart->schemas);
		$this->assertSame($schema, $jumpStart->schemas[0]);
	}

	public function testAddObject(): void
	{
		$jumpStart = new JumpStartData();
		$object    = [
			'collection' => 'blog',
			'id'         => 'test-post',
			'data'       => [
				'title'   => 'Test Post',
				'content' => 'Test content',
			],
		];

		$jumpStart->addObject($object);

		$this->assertCount(1, $jumpStart->objects);
		$this->assertSame($object, $jumpStart->objects[0]);
	}

	public function testAddFactory(): void
	{
		$jumpStart = new JumpStartData();
		$factory   = [
			'collection' => 'blog',
			'count'      => 10,
			'data'       => [
				'title'   => 'sentence',
				'content' => 'paragraphs',
			],
		];

		$jumpStart->addFactory($factory);

		$this->assertCount(1, $jumpStart->factory);
		$this->assertSame($factory, $jumpStart->factory[0]);
	}

	public function testToArray(): void
	{
		$jumpStart = new JumpStartData('Test Name', 'Test Description');
		$jumpStart->addReservedCollection('blog');
		$jumpStart->addCustomCollection([
			'id'     => 'custom',
			'name'   => 'Custom Collection',
			'schema' => 'blog',
		]);
		$jumpStart->addSchema([
			'id'   => 'test-schema',
			'name' => 'Test Schema',
		]);
		$jumpStart->addObject([
			'collection' => 'blog',
			'id'         => 'test-object',
			'data'       => ['title' => 'Test'],
		]);
		$jumpStart->addFactory([
			'collection' => 'blog',
			'count'      => 5,
		]);

		$array = $jumpStart->toArray();

		$this->assertIsArray($array);
		$this->assertArrayHasKey('version', $array);
		$this->assertArrayHasKey('name', $array);
		$this->assertArrayHasKey('description', $array);
		$this->assertArrayHasKey('collections', $array);
		$this->assertArrayHasKey('schemas', $array);
		$this->assertArrayHasKey('objects', $array);
		$this->assertArrayHasKey('factory', $array);

		$this->assertSame('1.0.0', $array['version']);
		$this->assertStringContainsString('Test Name', $array['name']);
		$this->assertStringContainsString('Test Description', $array['description']);

		$this->assertContains('blog', $array['collections']['reserved']);
		$this->assertCount(1, $array['collections']['custom']);
		$this->assertSame('custom', $array['collections']['custom'][0]['id']);

		$this->assertCount(1, $array['schemas']);
		$this->assertSame('test-schema', $array['schemas'][0]['id']);

		$this->assertCount(1, $array['objects']);
		$this->assertSame('blog', $array['objects'][0]['collection']);

		$this->assertCount(1, $array['factory']);
		$this->assertSame(5, $array['factory'][0]['count']);
	}

	public function testIsEmpty(): void
	{
		$jumpStart = new JumpStartData();

		// Should be empty initially
		$this->assertTrue($jumpStart->isEmpty());

		// Should not be empty after adding data
		$jumpStart->addReservedCollection('blog');
		$this->assertFalse($jumpStart->isEmpty());
	}

	public function testIsEmptyWithObjects(): void
	{
		$jumpStart = new JumpStartData();

		$jumpStart->addObject([
			'collection' => 'blog',
			'id'         => 'test',
			'data'       => [],
		]);

		$this->assertFalse($jumpStart->isEmpty());
	}

	public function testIsEmptyWithSchemas(): void
	{
		$jumpStart = new JumpStartData();

		$jumpStart->addSchema([
			'id'   => 'test-schema',
			'name' => 'Test',
		]);

		$this->assertFalse($jumpStart->isEmpty());
	}

	public function testIsEmptyWithFactory(): void
	{
		$jumpStart = new JumpStartData();

		$jumpStart->addFactory([
			'collection' => 'blog',
			'count'      => 1,
		]);

		$this->assertFalse($jumpStart->isEmpty());
	}

	public function testGetTotalObjectCount(): void
	{
		$jumpStart = new JumpStartData();

		// Initially should be 0
		$this->assertSame(0, $jumpStart->getTotalObjectCount());

		// Add specific objects
		$jumpStart->addObject(['collection' => 'blog', 'id' => 'post1', 'data' => []]);
		$jumpStart->addObject(['collection' => 'blog', 'id' => 'post2', 'data' => []]);
		$this->assertSame(2, $jumpStart->getTotalObjectCount());

		// Add factory definitions
		$jumpStart->addFactory(['collection' => 'blog', 'count' => 10]);
		$jumpStart->addFactory(['collection' => 'gallery', 'count' => 5]);

		// Should include factory counts
		$this->assertSame(17, $jumpStart->getTotalObjectCount()); // 2 + 10 + 5
	}

	public function testGetTotalObjectCountWithSpecificIds(): void
	{
		$jumpStart = new JumpStartData();

		// Factory with specific ID counts as 1 regardless of count value
		$jumpStart->addFactory(['collection' => 'blog', 'id' => 'specific-post', 'count' => 100]);

		$this->assertSame(1, $jumpStart->getTotalObjectCount());
	}
}
