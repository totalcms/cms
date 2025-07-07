<?php

use TotalCMS\Domain\JumpStart\Data\JumpStartData;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('JumpStart Basic Functionality', function () {
	it('can create and manipulate JumpStart data structures', function () {
		$jumpStart = new JumpStartData('Test JumpStart', 'Test description');
		
		expect($jumpStart->name)->toBe('Test JumpStart');
		expect($jumpStart->isEmpty())->toBeTrue();
		
		// Add some data
		$jumpStart->addReservedCollection('blog');
		$jumpStart->addObject([
			'collection' => 'blog',
			'id' => 'test-post',
			'data' => ['title' => 'Test Post', 'content' => 'Test content']
		]);
		
		expect($jumpStart->isEmpty())->toBeFalse();
		expect($jumpStart->getTotalObjectCount())->toBe(1);
		
		// Test serialization
		$array = $jumpStart->toArray();
		expect($array)->toHaveKey('version');
		expect($array)->toHaveKey('collections');
		expect($array)->toHaveKey('objects');
		expect($array['objects'])->toHaveCount(1);
		
		// Test JSON serialization
		$json = $jumpStart->toJson();
		expect($json)->toBeString();
		
		// Test deserialization
		$restored = JumpStartData::fromJson($json);
		expect($restored->name)->toBe('Test JumpStart');
		expect($restored->getTotalObjectCount())->toBe(1);
	});

	it('can handle factory definitions', function () {
		$jumpStart = new JumpStartData();
		
		// Add factory definitions
		$jumpStart->addFactory([
			'collection' => 'blog',
			'count' => 5,
			'data' => [
				'title' => 'sentence',
				'content' => 'paragraphs'
			]
		]);
		
		$jumpStart->addFactory([
			'collection' => 'text',
			'id' => 'specific-text',
			'data' => [
				'text' => 'word'
			]
		]);
		
		expect($jumpStart->getTotalObjectCount())->toBe(6); // 5 + 1
		
		$array = $jumpStart->toArray();
		expect($array['factory'])->toHaveCount(2);
		expect($array['factory'][0]['count'])->toBe(5);
		expect($array['factory'][1]['id'])->toBe('specific-text');
	});

	it('can handle custom schemas and collections', function () {
		$jumpStart = new JumpStartData();
		
		// Add custom schema
		$jumpStart->addSchema([
			'id' => 'product-schema',
			'name' => 'Product Schema',
			'type' => 'object',
			'properties' => [
				'name' => ['type' => 'string', 'field' => 'text'],
				'price' => ['type' => 'number', 'field' => 'number']
			]
		]);
		
		// Add custom collection
		$jumpStart->addCustomCollection([
			'id' => 'products',
			'name' => 'Products',
			'schema' => 'product-schema',
			'settings' => ['featured' => true]
		]);
		
		$array = $jumpStart->toArray();
		expect($array['schemas'])->toHaveCount(1);
		expect($array['schemas'][0]['id'])->toBe('product-schema');
		expect($array['collections']['custom'])->toHaveCount(1);
		expect($array['collections']['custom'][0]['id'])->toBe('products');
	});

	it('validates demo file can be loaded and parsed', function () {
		$demoPath = '/Users/joeworkman/Developer/totalcms/resources/jumpstart/demo.json';
		expect(file_exists($demoPath))->toBeTrue();
		
		$demoContent = file_get_contents($demoPath);
		$demoData = json_decode($demoContent, true);
		
		expect($demoData)->not->toBeNull();
		expect($demoData)->toHaveKey('version');
		expect($demoData)->toHaveKey('collections');
		expect($demoData)->toHaveKey('objects');
		expect($demoData)->toHaveKey('factory');
		
		// Test we can create JumpStartData from demo
		$jumpStart = JumpStartData::fromArray($demoData);
		expect($jumpStart->version)->toBe('1.0.0');
		expect($jumpStart->getTotalObjectCount())->toBeGreaterThan(0);
	});
});