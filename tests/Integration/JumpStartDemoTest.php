<?php

namespace Tests\Integration;

describe('JumpStart Demo Integration', function () {
	it('validates demo definition against jumpstart schema', function () {
		$demoPath = jumpstartResourcePath('demo.json');
		expect(file_exists($demoPath))->toBeTrue('Demo jumpstart file should exist');
		
		$demoContent = file_get_contents($demoPath);
		$demoDefinition = json_decode($demoContent, true);
		
		expect($demoDefinition)->not->toBeNull('Demo file should contain valid JSON');
		
		// Basic structure validation
		expect($demoDefinition)->toHaveKey('version');
		expect($demoDefinition)->toHaveKey('name');
		expect($demoDefinition)->toHaveKey('description');
		expect($demoDefinition)->toHaveKey('collections');
		expect($demoDefinition)->toHaveKey('schemas');
		expect($demoDefinition)->toHaveKey('objects');
		expect($demoDefinition)->toHaveKey('factory');
		
		// Version format validation
		expect($demoDefinition['version'])->toMatch('/^\d+\.\d+\.\d+$/');
		expect($demoDefinition['version'])->toBe('1.0.0');
		
		// Collections structure validation
		expect($demoDefinition['collections'])->toHaveKey('reserved');
		expect($demoDefinition['collections'])->toHaveKey('custom');
		expect($demoDefinition['collections']['reserved'])->toBeArray();
		expect($demoDefinition['collections']['custom'])->toBeArray();
		
		// Factory structure validation
		foreach ($demoDefinition['factory'] as $factoryItem) {
			expect($factoryItem)->toHaveKey('collection');
			
			// Should have either count or id
			$hasCount = array_key_exists('count', $factoryItem);
			$hasId = array_key_exists('id', $factoryItem);
			expect($hasCount || $hasId)->toBeTrue('Factory item should have either count or id');
		}
		
		// Objects structure validation
		foreach ($demoDefinition['objects'] as $object) {
			expect($object)->toHaveKey('collection');
			expect($object)->toHaveKey('id');
			expect($object)->toHaveKey('data');
		}
		
		// Schemas structure validation
		foreach ($demoDefinition['schemas'] as $schema) {
			expect($schema)->toHaveKey('id');
			expect($schema)->toHaveKey('type');
			expect($schema)->toHaveKey('properties');
		}
	});

	it('has valid demo data structure', function () {
		$demoPath = jumpstartResourcePath('demo.json');
		$demoContent = file_get_contents($demoPath);
		$demoDefinition = json_decode($demoContent, true);
		
		// Check that reserved collections are not empty
		expect($demoDefinition['collections']['reserved'])->not->toBeEmpty('Should have reserved collections');
		
		// Verify demo objects have expected structure
		$demoObjects = [
			'svg' => 'demosvg',
			'gallery' => 'demogallery', 
			'date' => 'demodate',
			'url' => 'demourl',
			'text' => 'demoheader',
			'styledtext' => 'demostyledtext'
		];

		foreach ($demoObjects as $collection => $expectedId) {
			$found = false;
			foreach ($demoDefinition['objects'] as $object) {
				if ($object['collection'] === $collection && $object['id'] === $expectedId) {
					$found = true;
					expect($object['data'])->not->toBeEmpty("Object '$expectedId' should have data");
					break;
				}
			}
			expect($found)->toBeTrue("Demo should contain object '$expectedId' in collection '$collection'");
		}

		// Verify factory items have valid structure
		foreach ($demoDefinition['factory'] as $factoryItem) {
			expect($factoryItem)->toHaveKey('collection');
			
			if (isset($factoryItem['count'])) {
				expect($factoryItem['count'])->toBeGreaterThan(0);
			}
		}

		// Verify products schema is properly defined
		$productsSchema = null;
		foreach ($demoDefinition['schemas'] as $schema) {
			if ($schema['id'] === 'products') {
				$productsSchema = $schema;
				break;
			}
		}
		expect($productsSchema)->not->toBeNull('Products schema should be defined');
		
		$expectedProperties = ['id', 'name', 'image', 'description', 'price', 'tags'];
		foreach ($expectedProperties as $property) {
			expect($productsSchema['properties'])->toHaveKey($property);
			expect($productsSchema['properties'][$property])->toHaveKey('field');
		}
		
		// Verify products collection is defined
		$productsCollection = null;
		foreach ($demoDefinition['collections']['custom'] as $collection) {
			if ($collection['id'] === 'products') {
				$productsCollection = $collection;
				break;
			}
		}
		expect($productsCollection)->not->toBeNull('Products collection should be defined');
		expect($productsCollection['schema'])->toBe('products', 'Products collection should use products schema');
	});

	it('has consistent factory data and schema relationships', function () {
		$demoPath = jumpstartResourcePath('demo.json');
		$demoContent = file_get_contents($demoPath);
		$demoDefinition = json_decode($demoContent, true);
		
		// Check that collections are properly defined
		$allCollections = array_merge(
			$demoDefinition['collections']['reserved'],
			array_column($demoDefinition['collections']['custom'], 'id')
		);
		
		// Just verify factory items and objects have collection references
		foreach ($demoDefinition['factory'] as $factoryItem) {
			expect($factoryItem)->toHaveKey('collection');
		}
		
		foreach ($demoDefinition['objects'] as $object) {
			expect($object)->toHaveKey('collection');
		}
		
		// Verify products factory has proper faker rules
		$productsFactory = null;
		foreach ($demoDefinition['factory'] as $factoryItem) {
			if ($factoryItem['collection'] === 'products' && isset($factoryItem['count'])) {
				$productsFactory = $factoryItem;
				break;
			}
		}
		expect($productsFactory)->not->toBeNull('Should have products factory with count');
		expect($productsFactory['count'])->toBeGreaterThan(0, 'Products factory should generate objects');
	});

	it('ensures important customer-facing objects exist', function () {
		$demoPath = jumpstartResourcePath('demo.json');
		$demoContent = file_get_contents($demoPath);
		$demoDefinition = json_decode($demoContent, true);
		
		// These are important objects that customers will see when starting
		$importantObjects = [
			'demoheader' => 'text',      // Welcome message
			'demourl' => 'url',          // Example URL
			'demostyledtext' => 'styledtext',  // Rich text example
			'demosvg' => 'svg'           // SVG example
		];
		
		foreach ($importantObjects as $objectId => $collection) {
			$found = false;
			foreach ($demoDefinition['objects'] as $object) {
				if ($object['id'] === $objectId && $object['collection'] === $collection) {
					$found = true;
					expect($object['data'])->not->toBeEmpty("Important object '$objectId' should have meaningful data");
					break;
				}
			}
			expect($found)->toBeTrue("Important customer-facing object '$objectId' should exist in collection '$collection'");
		}
		
		// Verify the welcome message content
		foreach ($demoDefinition['objects'] as $object) {
			if ($object['id'] === 'demoheader') {
				expect($object['data']['text'])->toContain('Total CMS');
				break;
			}
		}
	});
});