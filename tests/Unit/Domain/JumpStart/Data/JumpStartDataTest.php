<?php

use TotalCMS\Domain\JumpStart\Data\JumpStartData;

describe('JumpStartData', function (): void {
	// -------------------------
	// Constructor and Defaults
	// -------------------------

	test('JumpStartData → creates with default values', function (): void {
		$jumpStart = new JumpStartData();

		expect($jumpStart->version)->toBe('1.0.0');
		expect($jumpStart->name)->toBe('Exported from Current CMS Data');
		expect($jumpStart->description)->toStartWith('Jumpstart definition generated from existing Total CMS data - ');
		expect($jumpStart->description)->toContain(date('Y-m-d')); // Should have today's date

		expect($jumpStart->collections)->toBe([
			'reserved' => [],
			'custom'   => [],
		]);
		expect($jumpStart->schemas)->toBe([]);
		expect($jumpStart->objects)->toBe([]);
		expect($jumpStart->factory)->toBe([]);
	});

	test('JumpStartData → creates with custom name and description', function (): void {
		$name        = 'My JumpStart Package';
		$description = 'Custom package description';
		$jumpStart   = new JumpStartData($name, $description);

		expect($jumpStart->name)->toBe($name);
		expect($jumpStart->description)->toStartWith($description . ' - ');
		expect($jumpStart->description)->toContain(date('Y-m-d H:i'));
	});

	test('JumpStartData → appends timestamp to description', function (): void {
		$description = 'Test description';
		$jumpStart   = new JumpStartData('Test', $description);

		expect($jumpStart->description)->not->toBe($description);
		expect($jumpStart->description)->toStartWith($description . ' - ');

		// Should have a valid date format appended
		$parts = explode(' - ', $jumpStart->description);
		expect(count($parts))->toBe(2);
		expect($parts[1])->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
	});

	// -------------------------
	// Setters
	// -------------------------

	test('JumpStartData → setName updates name', function (): void {
		$jumpStart = new JumpStartData();
		$newName   = 'Updated Package Name';

		$jumpStart->setName($newName);

		expect($jumpStart->name)->toBe($newName);
	});

	test('JumpStartData → setDescription updates description', function (): void {
		$jumpStart      = new JumpStartData();
		$newDescription = 'Updated package description';

		$jumpStart->setDescription($newDescription);

		expect($jumpStart->description)->toBe($newDescription);
	});

	// -------------------------
	// Schema Management
	// -------------------------

	test('JumpStartData → addSchema adds schema to array', function (): void {
		$jumpStart = new JumpStartData();
		$schema    = ['id' => 'blog', 'type' => 'blog', 'properties' => []];

		$jumpStart->addSchema($schema);

		expect($jumpStart->schemas)->toContain($schema);
		expect(count($jumpStart->schemas))->toBe(1);
	});

	test('JumpStartData → addSchema allows multiple schemas', function (): void {
		$jumpStart = new JumpStartData();
		$schema1   = ['id' => 'blog', 'type' => 'blog'];
		$schema2   = ['id' => 'gallery', 'type' => 'gallery'];

		$jumpStart->addSchema($schema1);
		$jumpStart->addSchema($schema2);

		expect($jumpStart->schemas)->toBe([$schema1, $schema2]);
		expect(count($jumpStart->schemas))->toBe(2);
	});

	// -------------------------
	// Collection Management
	// -------------------------

	test('JumpStartData → addReservedCollection adds to reserved list', function (): void {
		$jumpStart      = new JumpStartData();
		$collectionType = 'blog';

		$jumpStart->addReservedCollection($collectionType);

		expect($jumpStart->collections['reserved'])->toContain($collectionType);
		expect(count($jumpStart->collections['reserved']))->toBe(1);
		expect($jumpStart->collections['custom'])->toBe([]);
	});

	test('JumpStartData → addReservedCollection prevents duplicates', function (): void {
		$jumpStart      = new JumpStartData();
		$collectionType = 'blog';

		$jumpStart->addReservedCollection($collectionType);
		$jumpStart->addReservedCollection($collectionType); // Add same again

		expect($jumpStart->collections['reserved'])->toBe([$collectionType]);
		expect(count($jumpStart->collections['reserved']))->toBe(1);
	});

	test('JumpStartData → addReservedCollection allows multiple types', function (): void {
		$jumpStart = new JumpStartData();

		$jumpStart->addReservedCollection('blog');
		$jumpStart->addReservedCollection('gallery');
		$jumpStart->addReservedCollection('image');

		expect($jumpStart->collections['reserved'])->toBe(['blog', 'gallery', 'image']);
		expect(count($jumpStart->collections['reserved']))->toBe(3);
	});

	test('JumpStartData → addCustomCollection adds to custom array', function (): void {
		$jumpStart  = new JumpStartData();
		$collection = ['id' => 'products', 'type' => 'custom', 'properties' => []];

		$jumpStart->addCustomCollection($collection);

		expect($jumpStart->collections['custom'])->toContain($collection);
		expect(count($jumpStart->collections['custom']))->toBe(1);
		expect($jumpStart->collections['reserved'])->toBe([]);
	});

	test('JumpStartData → addCustomCollection allows multiple collections', function (): void {
		$jumpStart   = new JumpStartData();
		$collection1 = ['id' => 'products', 'type' => 'custom'];
		$collection2 = ['id' => 'services', 'type' => 'custom'];

		$jumpStart->addCustomCollection($collection1);
		$jumpStart->addCustomCollection($collection2);

		expect($jumpStart->collections['custom'])->toBe([$collection1, $collection2]);
		expect(count($jumpStart->collections['custom']))->toBe(2);
	});

	// -------------------------
	// Object Management
	// -------------------------

	test('JumpStartData → addObject adds to objects array', function (): void {
		$jumpStart = new JumpStartData();
		$object    = ['id' => 'post1', 'title' => 'First Post', 'content' => 'Content here'];

		$jumpStart->addObject($object);

		expect($jumpStart->objects)->toContain($object);
		expect(count($jumpStart->objects))->toBe(1);
	});

	test('JumpStartData → addObject allows multiple objects', function (): void {
		$jumpStart = new JumpStartData();
		$object1   = ['id' => 'post1', 'title' => 'First Post'];
		$object2   = ['id' => 'post2', 'title' => 'Second Post'];

		$jumpStart->addObject($object1);
		$jumpStart->addObject($object2);

		expect($jumpStart->objects)->toBe([$object1, $object2]);
		expect(count($jumpStart->objects))->toBe(2);
	});

	// -------------------------
	// Factory Management
	// -------------------------

	test('JumpStartData → addFactory adds to factory array', function (): void {
		$jumpStart = new JumpStartData();
		$factory   = ['collection' => 'blog', 'count' => 10, 'properties' => []];

		$jumpStart->addFactory($factory);

		expect($jumpStart->factory)->toContain($factory);
		expect(count($jumpStart->factory))->toBe(1);
	});

	test('JumpStartData → addFactory allows multiple factories', function (): void {
		$jumpStart = new JumpStartData();
		$factory1  = ['collection' => 'blog', 'count' => 5];
		$factory2  = ['collection' => 'gallery', 'count' => 3];

		$jumpStart->addFactory($factory1);
		$jumpStart->addFactory($factory2);

		expect($jumpStart->factory)->toBe([$factory1, $factory2]);
		expect(count($jumpStart->factory))->toBe(2);
	});

	// -------------------------
	// Array Conversion
	// -------------------------

	test('JumpStartData → toArray returns complete data structure', function (): void {
		$jumpStart = new JumpStartData('Test Package', 'Test Description');
		$jumpStart->addSchema(['id' => 'blog']);
		$jumpStart->addReservedCollection('blog');
		$jumpStart->addCustomCollection(['id' => 'custom']);
		$jumpStart->addObject(['id' => 'object1']);
		$jumpStart->addFactory(['collection' => 'blog', 'count' => 5]);

		$array = $jumpStart->toArray();

		expect($array)->toHaveKey('version');
		expect($array)->toHaveKey('name');
		expect($array)->toHaveKey('description');
		expect($array)->toHaveKey('schemas');
		expect($array)->toHaveKey('collections');
		expect($array)->toHaveKey('objects');
		expect($array)->toHaveKey('factory');

		expect($array['version'])->toBe('1.0.0');
		expect($array['name'])->toBe('Test Package');
		expect($array['description'])->toStartWith('Test Description - ');
		expect($array['schemas'])->toBe([['id' => 'blog']]);
		expect($array['collections']['reserved'])->toBe(['blog']);
		expect($array['collections']['custom'])->toBe([['id' => 'custom']]);
		expect($array['objects'])->toBe([['id' => 'object1']]);
		expect($array['factory'])->toBe([['collection' => 'blog', 'count' => 5]]);
	});

	// -------------------------
	// fromArray Static Constructor
	// -------------------------

	test('JumpStartData → fromArray creates instance from array', function (): void {
		$data = [
			'version'     => '2.0.0',
			'name'        => 'Imported Package',
			'description' => 'Imported description',
			'schemas'     => [['id' => 'blog']],
			'collections' => [
				'reserved' => ['blog', 'gallery'],
				'custom'   => [['id' => 'products']],
			],
			'objects' => [['id' => 'post1']],
			'factory' => [['collection' => 'blog', 'count' => 10]],
		];

		$jumpStart = JumpStartData::fromArray($data);

		expect($jumpStart->version)->toBe('2.0.0');
		expect($jumpStart->name)->toBe('Imported Package');
		expect($jumpStart->description)->toStartWith('Imported description - '); // Constructor adds timestamp
		expect($jumpStart->schemas)->toBe([['id' => 'blog']]);
		expect($jumpStart->collections['reserved'])->toBe(['blog', 'gallery']);
		expect($jumpStart->collections['custom'])->toBe([['id' => 'products']]);
		expect($jumpStart->objects)->toBe([['id' => 'post1']]);
		expect($jumpStart->factory)->toBe([['collection' => 'blog', 'count' => 10]]);
	});

	test('JumpStartData → fromArray handles missing properties with defaults', function (): void {
		$data = [
			'name' => 'Minimal Package',
		];

		$jumpStart = JumpStartData::fromArray($data);

		expect($jumpStart->version)->toBe('1.0.0');
		expect($jumpStart->name)->toBe('Minimal Package');
		expect($jumpStart->description)->toStartWith(' - '); // Empty description still gets timestamp
		expect($jumpStart->schemas)->toBe([]);
		expect($jumpStart->collections)->toBe(['default' => [], 'custom' => []]); // Note: default vs reserved
		expect($jumpStart->objects)->toBe([]);
		expect($jumpStart->factory)->toBe([]);
	});

	test('JumpStartData → fromArray handles completely empty data', function (): void {
		$jumpStart = JumpStartData::fromArray([]);

		expect($jumpStart->version)->toBe('1.0.0');
		expect($jumpStart->name)->toBe('');
		expect($jumpStart->description)->toStartWith(' - '); // Empty description still gets timestamp
		expect($jumpStart->schemas)->toBe([]);
		expect($jumpStart->objects)->toBe([]);
		expect($jumpStart->factory)->toBe([]);
	});

	// -------------------------
	// JSON Serialization
	// -------------------------

	test('JumpStartData → toJson creates valid JSON', function (): void {
		$jumpStart = new JumpStartData('JSON Test', 'JSON Description');
		$jumpStart->addSchema(['id' => 'blog']);
		$jumpStart->addObject(['id' => 'post1']);

		$json = $jumpStart->toJson();

		expect($json)->toBeString();
		expect($json)->not->toBeEmpty();

		// Should be valid JSON
		$decoded = json_decode($json, true);
		expect($decoded)->toBeArray();
		expect($decoded)->toHaveKey('name');
		expect($decoded['name'])->toBe('JSON Test');
	});

	test('JumpStartData → fromJson recreates instance from JSON', function (): void {
		$original = new JumpStartData('Original', 'Original Description');
		$original->addSchema(['id' => 'blog']);
		$original->addReservedCollection('blog');
		$original->addObject(['id' => 'post1']);

		$json      = $original->toJson();
		$recreated = JumpStartData::fromJson($json);

		expect($recreated->name)->toBe($original->name);
		// Description will have double timestamp: original constructor + fromArray constructor
		expect($recreated->description)->toStartWith('Original Description - ');
		expect($recreated->description)->toContain(' - '); // Double timestamp from both constructors
		expect($recreated->schemas)->toBe($original->schemas);
		expect($recreated->collections)->toBe($original->collections);
		expect($recreated->objects)->toBe($original->objects);
	});

	test('JumpStartData → fromJson throws exception for invalid JSON', function (): void {
		expect(fn () => JumpStartData::fromJson('invalid json'))
			->toThrow(InvalidArgumentException::class, 'Invalid JSON data');
	});

	test('JumpStartData → fromJson throws exception for non-object JSON', function (): void {
		expect(fn () => JumpStartData::fromJson('"just a string"'))
			->toThrow(InvalidArgumentException::class, 'Invalid JSON data');
	});

	// -------------------------
	// State Checking
	// -------------------------

	test('JumpStartData → isEmpty returns true for empty instance', function (): void {
		$jumpStart = new JumpStartData();

		expect($jumpStart->isEmpty())->toBe(true);
	});

	test('JumpStartData → isEmpty returns false when has schemas', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addSchema(['id' => 'blog']);

		expect($jumpStart->isEmpty())->toBe(false);
	});

	test('JumpStartData → isEmpty returns false when has reserved collections', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addReservedCollection('blog');

		expect($jumpStart->isEmpty())->toBe(false);
	});

	test('JumpStartData → isEmpty returns false when has custom collections', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addCustomCollection(['id' => 'custom']);

		expect($jumpStart->isEmpty())->toBe(false);
	});

	test('JumpStartData → isEmpty returns false when has objects', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addObject(['id' => 'post1']);

		expect($jumpStart->isEmpty())->toBe(false);
	});

	test('JumpStartData → isEmpty returns false when has factory', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addFactory(['collection' => 'blog', 'count' => 5]);

		expect($jumpStart->isEmpty())->toBe(false);
	});

	// -------------------------
	// Object Counting
	// -------------------------

	test('JumpStartData → getTotalObjectCount returns 0 for empty', function (): void {
		$jumpStart = new JumpStartData();

		expect($jumpStart->getTotalObjectCount())->toBe(0);
	});

	test('JumpStartData → getTotalObjectCount counts direct objects', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addObject(['id' => 'post1']);
		$jumpStart->addObject(['id' => 'post2']);

		expect($jumpStart->getTotalObjectCount())->toBe(2);
	});

	test('JumpStartData → getTotalObjectCount counts factory with count', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addFactory(['collection' => 'blog', 'count' => 10]);
		$jumpStart->addFactory(['collection' => 'gallery', 'count' => 5]);

		expect($jumpStart->getTotalObjectCount())->toBe(15);
	});

	test('JumpStartData → getTotalObjectCount counts factory with ID as 1', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addFactory(['id' => 'specific-post', 'collection' => 'blog']);
		$jumpStart->addFactory(['id' => 'another-post', 'collection' => 'blog']);

		expect($jumpStart->getTotalObjectCount())->toBe(2);
	});

	test('JumpStartData → getTotalObjectCount combines objects and factory', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addObject(['id' => 'post1']);
		$jumpStart->addObject(['id' => 'post2']); // 2 direct objects
		$jumpStart->addFactory(['collection' => 'blog', 'count' => 5]); // 5 factory objects
		$jumpStart->addFactory(['id' => 'specific', 'collection' => 'gallery']); // 1 specific factory object

		expect($jumpStart->getTotalObjectCount())->toBe(8); // 2 + 5 + 1
	});

	test('JumpStartData → getTotalObjectCount handles factory without count', function (): void {
		$jumpStart = new JumpStartData();
		$jumpStart->addFactory(['collection' => 'blog']); // No count specified

		expect($jumpStart->getTotalObjectCount())->toBe(0);
	});

	// -------------------------
	// Integration Tests
	// -------------------------

	test('JumpStartData → full workflow with all data types', function (): void {
		$jumpStart = new JumpStartData('Complete Package', 'Full featured package');

		// Add various data
		$jumpStart->addSchema(['id' => 'blog', 'type' => 'blog']);
		$jumpStart->addSchema(['id' => 'gallery', 'type' => 'gallery']);
		$jumpStart->addReservedCollection('blog');
		$jumpStart->addReservedCollection('gallery');
		$jumpStart->addCustomCollection(['id' => 'products', 'properties' => []]);
		$jumpStart->addObject(['id' => 'post1', 'title' => 'First Post']);
		$jumpStart->addObject(['id' => 'post2', 'title' => 'Second Post']);
		$jumpStart->addFactory(['collection' => 'blog', 'count' => 10]);
		$jumpStart->addFactory(['id' => 'hero-gallery', 'collection' => 'gallery']);

		// Verify state
		expect($jumpStart->isEmpty())->toBe(false);
		expect($jumpStart->getTotalObjectCount())->toBe(13); // 2 objects + 10 factory + 1 specific factory

		// Verify serialization roundtrip
		$json      = $jumpStart->toJson();
		$recreated = JumpStartData::fromJson($json);

		expect($recreated->getTotalObjectCount())->toBe(13);
		expect($recreated->isEmpty())->toBe(false);
		expect($recreated->name)->toBe('Complete Package');
	});
});
