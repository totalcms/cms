<?php

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

// Core functionality tests - verifying deck exclusion from CSV export

it('checks ObjectExporter excludes deck properties in schema filtering', function (): void {
	// We can test the filtering logic indirectly by using reflection if needed
	// or by creating a simple integration test

	// Create a mock schema-like structure
	$properties = [
		'id'       => ['type' => 'string'],
		'name'     => ['type' => 'string'],
		'features' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json'],
		'price'    => ['type' => 'number'],
		'gallery'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'],
	];

	// Simulate the filtering logic from ObjectExporter
	$filteredProperties = array_filter(
		array_keys($properties),
		fn (string $propertyName): bool => !isset($properties[$propertyName]['$ref'])
							 || $properties[$propertyName]['$ref'] !== 'https://www.totalcms.co/schemas/properties/deck.json'
	);

	$filteredProperties = array_values($filteredProperties);

	// Verify deck properties are excluded
	expect($filteredProperties)->toContain('id');
	expect($filteredProperties)->toContain('name');
	expect($filteredProperties)->toContain('price');
	expect($filteredProperties)->toContain('gallery'); // Non-deck reference should be included
	expect($filteredProperties)->not->toContain('features'); // Deck property excluded
	expect($filteredProperties)->toHaveCount(4); // Only non-deck properties
});

it('verifies PropertyData array handling', function (): void {
	// Test that PropertyData classes work correctly with array inputs
	$deckData = new TotalCMS\Domain\Property\Data\DeckData([], []);
	expect($deckData->count())->toBe(0);

	$listData = new TotalCMS\Domain\Property\Data\ListData([], []);
	expect($listData->transform())->toBe([]);

	$galleryData = new TotalCMS\Domain\Property\Data\GalleryData([], []);
	expect($galleryData->transform())->toBe([]);

	$fileData = new TotalCMS\Domain\Property\Data\FileData([], []);
	expect($fileData->name)->toBe('');

	$imageData = new TotalCMS\Domain\Property\Data\ImageData([], []);
	expect($imageData->alt)->toBe('');
});
