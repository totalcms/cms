<?php

use TotalCMS\Domain\Property\Data\DateData;

// Test that DateData processes dates correctly (the core functionality tested in deck processing)
it('DateData converts date field from HTML datetime-local to ISO format', function () {
	// HTML datetime-local format (what comes from forms)
	$htmlDateValue = '2025-08-11T00:00';

	// Create DateData instance directly (this is what PropertyFactory calls internally)
	$dateData = new DateData($htmlDateValue);

	// Should be DateData instance
	expect($dateData)->toBeInstanceOf(DateData::class);

	// Get the transformed value
	$transformedValue = $dateData->transform();

	// Should be ISO 8601 format with timezone
	expect($transformedValue)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
	expect($transformedValue)->not()->toBe($htmlDateValue);

	echo "Original: $htmlDateValue\n";
	echo "Transformed: $transformedValue\n";
});

// Test that DateData processes datetime fields correctly
it('DateData converts datetime field from HTML datetime-local to ISO format', function () {
	// HTML datetime-local format
	$htmlDatetimeValue = '2025-08-11T14:30';

	// Create DateData instance directly
	$dateData = new DateData($htmlDatetimeValue);

	// Should be DateData instance
	expect($dateData)->toBeInstanceOf(DateData::class);

	// Get the transformed value
	$transformedValue = $dateData->transform();

	// Should be ISO 8601 format
	expect($transformedValue)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
	expect($transformedValue)->not()->toBe($htmlDatetimeValue);

	echo "Original: $htmlDatetimeValue\n";
	echo "Transformed: $transformedValue\n";
});
