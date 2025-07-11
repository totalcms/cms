<?php

use TotalCMS\Domain\Playground\Data\PlaygroundData;

it('defines the correct collection ID constant', function (): void {
	expect(PlaygroundData::COLLECTION_ID)->toBe('playground');
});

it('provides static access to collection ID', function (): void {
	$collectionId = PlaygroundData::COLLECTION_ID;
	expect($collectionId)->toBeString();
	expect($collectionId)->not()->toBeEmpty();
});

it('uses consistent collection naming', function (): void {
	// Verify the collection ID follows expected naming conventions
	expect(PlaygroundData::COLLECTION_ID)->toMatch('/^[a-z]+$/');
	expect(strlen(PlaygroundData::COLLECTION_ID))->toBeGreaterThan(3);
});