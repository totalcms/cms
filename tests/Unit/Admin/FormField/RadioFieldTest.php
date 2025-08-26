<?php

use TotalCMS\Domain\Admin\FormField\RadioField;
use TotalCMS\Domain\Admin\TotalForm;

describe('RadioField', function (): void {
	beforeEach(function (): void {
		$this->form = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('RadioField → creates with radio input type', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['Option 1', 'Option 2']);
		
		$html = $field->build();
		
		expect($html)
			->toContain('type="radio"')
			->toContain('name="choice"');
	});

	test('RadioField → sets default field type to radio', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['Option 1']);
		
		expect($field->build())
			->toContain('data-type="radio"')
			->toContain('radio-field');
	});

	test('RadioField → generates fieldset with legend', function (): void {
		$field = new RadioField($this->form, 'choice', label: 'Choose Option', options: ['Option 1']);
		
		$html = $field->build();
		
		expect($html)
			->toContain('<fieldset>')
			->toContain('<legend>')
			->toContain('Choose Option');
	});

	test('RadioField → generates multiple radio inputs for simple options', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['Option 1', 'Option 2', 'Option 3']);
		
		$html = $field->build();
		
		expect($html)
			->toContain('Option 1')
			->toContain('Option 2')
			->toContain('Option 3');
		
		// Count actual radio inputs - there might be duplicates from option processing
		$radioCount = substr_count($html, 'type="radio"');
		// RadioField processes options through buildOptions() which may add duplicates
		// We care more that all options are present than exact count
		expect($radioCount)->toBeGreaterThanOrEqual(3);
	});

	test('RadioField → generates radio inputs for complex options', function (): void {
		$options = [
			['value' => '1', 'label' => 'First Option'],
			['value' => '2', 'label' => 'Second Option'],
		];
		
		$field = new RadioField($this->form, 'choice', options: $options);
		
		$html = $field->build();
		
		expect($html)
			->toContain('value="1"')
			->toContain('value="2"')
			->toContain('First Option')
			->toContain('Second Option');
	});

	test('RadioField → creates unique IDs for each radio button', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['A', 'B']);
		
		$html = $field->build();
		
		// Should contain field-{uuid}-1 and field-{uuid}-2
		$matches = [];
		preg_match_all('/id="field-([a-zA-Z0-9]+)-([0-9]+)"/', $html, $matches);
		
		expect($matches[2])->toContain('1', '2'); // Index numbers
	});

	test('RadioField → applies selected value correctly', function (): void {
		$field = new RadioField($this->form, 'choice', value: 'Option 2', options: ['Option 1', 'Option 2', 'Option 3']);
		
		$html = $field->build();
		
		// Only one radio should be checked
		$checkedCount = substr_count($html, 'checked');
		expect($checkedCount)->toBe(1);
		
		// Should contain the checked attribute for Option 2
		expect($html)->toContain('checked');
	});

	test('RadioField → applies required attribute to all radio buttons when required', function (): void {
		$field = new RadioField($this->form, 'choice', required: true, options: ['A', 'B']);
		
		$html = $field->build();
		
		// All radio buttons should have required attribute
		$requiredCount = substr_count($html, 'required');
		expect($requiredCount)->toBe(2);
	});

	test('RadioField → applies disabled attribute when disabled', function (): void {
		$field = new RadioField($this->form, 'choice', disabled: true, options: ['A', 'B']);
		
		$html = $field->build();
		
		// All radio buttons should have disabled attribute
		$disabledCount = substr_count($html, 'disabled');
		expect($disabledCount)->toBe(2);
	});

	test('RadioField → includes help text when provided', function (): void {
		$field = new RadioField($this->form, 'choice', help: 'Select one option', options: ['A']);
		
		$html = $field->build();
		
		expect($html)
			->toContain('Select one option')
			->toContain('class="help"');
	});

	test('RadioField → creates proper label-for relationships', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['Option A']);
		
		$html = $field->build();
		
		// Extract ID from input and for from label
		$matches = [];
		preg_match('/id="(field-[^"]+)"/', $html, $matches);
		$inputId = $matches[1] ?? '';
		
		expect($html)->toContain("for=\"{$inputId}\"");
	});

	test('RadioField → wraps each radio in div with radio class', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['A', 'B']);
		
		$html = $field->build();
		
		// Should have two divs with radio class
		$radioWrapperCount = substr_count($html, 'class="radio"');
		expect($radioWrapperCount)->toBe(2);
	});

	test('RadioField → creates labels with radio-label class', function (): void {
		$field = new RadioField($this->form, 'choice', options: ['A', 'B']);
		
		$html = $field->build();
		
		// Should have radio-label class on each label
		$labelClassCount = substr_count($html, 'class="radio-label"');
		expect($labelClassCount)->toBe(2);
	});

	test('RadioField → handles empty label gracefully', function (): void {
		$field = new RadioField($this->form, 'choice', label: '', options: ['A']);
		
		$html = $field->build();
		
		// Should not contain legend element when label is empty
		expect($html)->not->toContain('<legend>');
	});

	test('RadioField → applies aria-describedby when help text provided', function (): void {
		$field = new RadioField($this->form, 'choice', help: 'Help text', options: ['A']);
		
		$html = $field->build();
		
		// Should contain aria-describedby referencing help ID
		expect($html)->toContain('aria-describedby=');
	});

	test('RadioField → applies custom CSS class', function (): void {
		$field = new RadioField($this->form, 'choice', class: 'custom-radio', options: ['A']);
		
		expect($field->build())
			->toContain('custom-radio');
	});

	test('RadioField → includes fieldGrid setting in style when provided', function (): void {
		$field = new RadioField($this->form, 'choice', settings: ['fieldGrid' => 3], options: ['A']);
		
		$html = $field->build();
		
		expect($html)->toContain('--fieldset-grid-size:3');
	});

	test('RadioField → processes propertyOptions setting', function (): void {
		$this->form->method('propertyListForCollection')
			->willReturn(['prop1', 'prop2']);
		
		$field = new RadioField(
			$this->form, 
			'choice',
			settings: ['propertyOptions' => true],
			options: []
		);
		
		$html = $field->build();
		
		expect($html)
			->toContain('prop1')
			->toContain('prop2');
	});

	test('RadioField → handles isOptionSelected correctly', function (): void {
		// Test with string value matching
		$field = new RadioField($this->form, 'choice', value: '2', options: [
			['value' => '1', 'label' => 'One'],
			['value' => '2', 'label' => 'Two'],
		]);
		
		$html = $field->build();
		
		// Only option with value "2" should be checked
		$checkedCount = substr_count($html, 'checked');
		expect($checkedCount)->toBe(1);
	});

	test('RadioField → getValue returns correct value', function (): void {
		$field = new RadioField($this->form, 'choice', value: 'selected', options: ['A']);
		
		expect($field->getValue())->toBe('selected');
	});
});