<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormGridBuilder;

describe('FormGridBuilder parsing', function (): void {
	test('--- is a divider, --- X and --- X --- are headers', function (): void {
		$b = new FormGridBuilder("a\n---\nb\n--- New Header\nc\n--- Old Header ---");
		// section titles, in order, via the public getSections-like accessor:
		expect($b->sectionTitles())->toBe(['New Header', 'Old Header']);
		expect($b->dividerCount())->toBe(1);
	});

	test('parses a [[ ]] block into a fieldset with legend, members and inner grid', function (): void {
		$b  = new FormGridBuilder("id id\n[[ My Legend\nfield1 field2\nfield3 field4\n]]");
		$fs = $b->getFieldsets();
		expect($fs)->toHaveCount(1);
		expect($fs[0]['legend'])->toBe('My Legend');
		expect($fs[0]['fields'])->toBe(['field1', 'field2', 'field3', 'field4']);
		expect($fs[0]['inner'])->toBeInstanceOf(FormGridBuilder::class);
		// the fieldset's members are NOT outer fields:
		expect($b->getFieldNames())->toBe(['id']);
	});

	test('[[ with no text means no legend; unclosed [[ is lenient', function (): void {
		$b = new FormGridBuilder("[[\nfield1 field2");           // never closed
		$fs = $b->getFieldsets();
		expect($fs[0]['legend'])->toBeNull();
		expect($fs[0]['fields'])->toBe(['field1', 'field2']);
	});
});
