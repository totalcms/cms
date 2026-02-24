<?php

use TotalCMS\Domain\Admin\FormField\ToggleField;
use TotalCMS\Domain\Admin\TotalForm;

describe('ToggleField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('ToggleField → creates with checkbox input type', function (): void {
		$field = new ToggleField($this->form, 'toggle');

		$html = $field->build();

		expect($html)
			->toContain('type="checkbox"')
			->toContain('name="toggle"');
	});

	test('ToggleField → sets default field type to toggle', function (): void {
		$field = new ToggleField($this->form, 'toggle');

		expect($field->build())
			->toContain('data-type="toggle"')
			->toContain('toggle-field');
	});

	test('ToggleField → creates switch wrapper structure', function (): void {
		$field = new ToggleField($this->form, 'toggle');

		$html = $field->build();

		expect($html)
			->toContain('<div class="switch">')
			->toContain('<div class="form-group">');
	});

	test('ToggleField → includes two labels (one in switch, one outside)', function (): void {
		$field = new ToggleField($this->form, 'toggle', label: 'Enable Feature');

		$html = $field->build();

		// Should contain two label elements
		$labelCount = substr_count($html, '<label');
		expect($labelCount)->toBe(2);

		// Should contain the label text twice
		$textCount = substr_count($html, 'Enable Feature');
		expect($textCount)->toBe(2);
	});

	test('ToggleField → switch label has aria-hidden attribute', function (): void {
		$field = new ToggleField($this->form, 'toggle', label: 'Test');

		$html = $field->build();

		expect($html)->toContain('aria-hidden="true"');
	});

	test('ToggleField → applies checked attribute when value is true', function (): void {
		$field = new ToggleField($this->form, 'toggle', value: true);

		$html = $field->build();

		expect($html)->toContain('checked');
	});

	test('ToggleField → does not apply checked attribute when value is false', function (): void {
		$field = new ToggleField($this->form, 'toggle', value: false);

		$html = $field->build();

		expect($html)->not->toContain('checked');
	});

	test('ToggleField → handles string "true" as checked', function (): void {
		$field = new ToggleField($this->form, 'toggle', value: 'true');

		$html = $field->build();

		expect($html)->toContain('checked');
	});

	test('ToggleField → handles string "1" as checked', function (): void {
		$field = new ToggleField($this->form, 'toggle', value: '1');

		$html = $field->build();

		expect($html)->toContain('checked');
	});

	test('ToggleField → handles integer 1 as checked', function (): void {
		$field = new ToggleField($this->form, 'toggle', value: 1);

		$html = $field->build();

		expect($html)->toContain('checked');
	});

	test('ToggleField → includes help text when provided', function (): void {
		$field = new ToggleField($this->form, 'toggle', help: 'Toggle this setting');

		$html = $field->build();

		expect($html)
			->toContain('Toggle this setting')
			->toContain('class="help"');
	});

	test('ToggleField → applies aria-describedby when help text provided', function (): void {
		$field = new ToggleField($this->form, 'toggle', help: 'Help text');

		$html = $field->build();

		expect($html)->toContain('aria-describedby=');
	});

	test('ToggleField → does not include required attribute (inherits from CheckboxField)', function (): void {
		$field = new ToggleField($this->form, 'toggle', required: true);

		$html = $field->build();

		// CheckboxField specifically sets required to null to allow unchecked boxes to save
		expect($html)->not->toContain('required=""');
	});

	test('ToggleField → applies custom CSS class', function (): void {
		$field = new ToggleField($this->form, 'toggle', class: 'custom-toggle');

		expect($field->build())
			->toContain('custom-toggle');
	});

	test('ToggleField → getValue returns correct value', function (): void {
		$field = new ToggleField($this->form, 'toggle', value: true);

		expect($field->getValue())->toBe(true);
	});

	test('ToggleField → disable method does not affect checkbox input (CheckboxField limitation)', function (): void {
		$field = new ToggleField($this->form, 'toggle');
		$field->disable();

		$html = $field->build();

		// CheckboxField overrides formFieldAttributes() and doesn't include disabled/readonly
		// This is actually a design limitation - checkboxes don't inherit disable functionality
		expect($html)
			->not->toContain('disabled')
			->not->toContain('readonly');
	});

	test('ToggleField → includes settings in data-settings when provided', function (): void {
		$field = new ToggleField($this->form, 'toggle', settings: ['custom' => 'value']);

		$html = $field->build();

		expect($html)->toContain('data-settings=');
	});

	test('ToggleField → creates proper label-for relationships', function (): void {
		$field = new ToggleField($this->form, 'toggle', label: 'Test Toggle');

		$html = $field->build();

		// Extract ID from input and check if labels reference it
		$matches = [];
		preg_match('/id="(field-[^"]+)"/', $html, $matches);
		$inputId = $matches[1] ?? '';

		// Both labels should reference the same input ID
		$forCount = substr_count($html, "for=\"{$inputId}\"");
		expect($forCount)->toBe(2);
	});

	test('ToggleField → handles empty label gracefully', function (): void {
		$field = new ToggleField($this->form, 'toggle', label: '');

		$html = $field->build();

		// Should still create the structure but with empty labels
		expect($html)
			->toContain('<div class="switch">')
			->toContain('<label');
	});

	test('ToggleField → uses unique UUID for field ID', function (): void {
		$field1 = new ToggleField($this->form, 'toggle1');
		$field2 = new ToggleField($this->form, 'toggle2');

		$html1 = $field1->build();
		$html2 = $field2->build();

		// Extract IDs from both fields
		$matches1 = [];
		$matches2 = [];
		preg_match('/id="(field-[^"]+)"/', $html1, $matches1);
		preg_match('/id="(field-[^"]+)"/', $html2, $matches2);

		expect($matches1[1])->not->toBe($matches2[1]);
	});
});
