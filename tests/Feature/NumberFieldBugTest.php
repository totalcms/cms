<?php

namespace Tests\Feature;

use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

function numberTestSchema(): array
{
	return [
		'id'          => 'numberbug-schema',
		'description' => 'Schema for testing number field bug',
		'properties'  => [
			'quantity' => [
				'type'    => 'number',
				'label'   => 'Quantity',
				'field'   => 'number',
				'default' => 1,
			],
			'price' => [
				'type'    => 'number',
				'label'   => 'Price',
				'field'   => 'number',
				'default' => 0.00,
			],
			'stock' => [
				'type'    => 'number',
				'label'   => 'Stock',
				'field'   => 'number',
				'default' => 10,
			],
		],
		'required' => ['quantity', 'price', 'stock'],
		'index'    => ['quantity', 'price', 'stock'],
	];
}

function numberTestCollection(): array
{
	return [
		'id'     => 'numberbug',
		'label'  => 'Number Field Tests',
		'schema' => 'numberbug-schema',
	];
}

it('reproduces the zero value bug in number fields', function (): void {
	$schema     = numberTestSchema();
	$collection = numberTestCollection();

	// Create the schema and collection
	postJson('/api/schemas', $schema)->assertOk();
	postJson('/api/collections', $collection)->assertOk();

	try {
		// Test object with zero values
		$objectWithZeros = [
			'id'       => 'test-zeros-' . uniqid(),
			'quantity' => 0,    // This should be saved as 0, not blank
			'price'    => 0.0,     // This should be saved as 0.0, not blank
			'stock'    => 0,        // This should be saved as 0, not blank
		];

		// Save the object
		$response = postJson('/api/collections/numberbug', $objectWithZeros);
		$response->assertOk();

		$savedObject = json_decode($response->getBody()->getContents(), true);

		// Check if zero values were preserved (data is wrapped in Fractal transformer)
		$data = $savedObject['data'];
		expect($data['quantity'])->toBe(0, 'Zero integer should be preserved');
		expect($data['price'])->toBe(0, 'Zero float should be preserved'); // JSON serializes 0.0 as 0 (integer)
		expect($data['stock'])->toBe(0, 'Zero integer should be preserved');
	} finally {
		// Clean up
		if (file_exists(schemaPath('numberbug-schema'))) {
			unlink(schemaPath('numberbug-schema'));
		}
		if (file_exists(collectionPath('numberbug') . '.meta.json')) {
			unlink(collectionPath('numberbug') . '.meta.json');
		}
		if (file_exists(objectPath('numberbug', 'test-zeros'))) {
			unlink(objectPath('numberbug', 'test-zeros'));
		}
	}
});

it('tests that non-zero values work correctly', function (): void {
	$schema     = numberTestSchema();
	$collection = numberTestCollection();

	// Create the schema and collection
	postJson('/api/schemas', $schema)->assertOk();
	postJson('/api/collections', $collection)->assertOk();

	try {
		// Test object with non-zero values
		$objectWithValues = [
			'id'       => 'test-values',
			'quantity' => 5,
			'price'    => 29.99,
			'stock'    => 100,
		];

		// Save the object
		$response    = postJson('/api/collections/numberbug', $objectWithValues)->assertOk();
		$savedObject = json_decode($response->getBody()->getContents(), true);

		// Check values were preserved (data is wrapped in Fractal transformer)
		$data = $savedObject['data'];
		expect($data['quantity'])->toBe(5);
		expect($data['price'])->toBe(29.99);
		expect($data['stock'])->toBe(100);
	} finally {
		// Clean up
		if (file_exists(schemaPath('numberbug-schema'))) {
			unlink(schemaPath('numberbug-schema'));
		}
		if (file_exists(collectionPath('numberbug') . '.meta.json')) {
			unlink(collectionPath('numberbug') . '.meta.json');
		}
		if (file_exists(objectPath('numberbug', 'test-values'))) {
			unlink(objectPath('numberbug', 'test-values'));
		}
	}
});
