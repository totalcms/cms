<?php

use TotalCMS\Domain\Admin\FormField\PasswordField;
use TotalCMS\Domain\Admin\TotalForm;

describe('PasswordField', function (): void {
	beforeEach(function (): void {
		$this->form = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('PasswordField → creates with password input type', function (): void {
		$field = new PasswordField($this->form, 'password');
		
		$html = $field->build();
		
		expect($html)
			->toContain('type="password"')
			->toContain('name="password"');
	});

	test('PasswordField → sets default field type to password', function (): void {
		$field = new PasswordField($this->form, 'password');
		
		expect($field->build())
			->toContain('data-type="password"')
			->toContain('password-field');
	});

	test('PasswordField → generates two password inputs', function (): void {
		$field = new PasswordField($this->form, 'password');
		
		$html = $field->build();
		
		// Should contain main password input and confirm password input
		expect($html)
			->toContain('name="password"')
			->toContain('name="password-confirm"');
	});

	test('PasswordField → generates two input IDs', function (): void {
		$field = new PasswordField($this->form, 'password');
		
		$html = $field->build();
		
		// Should contain both IDs (with unique UUIDs)
		$matches = [];
		preg_match_all('/id="field-([a-zA-Z0-9]+)(-confirm)?"/', $html, $matches);
		
		expect($matches[0])->toHaveLength(2); // Two ID attributes found
	});

	test('PasswordField → includes label when provided', function (): void {
		$field = new PasswordField($this->form, 'password', label: 'Password');
		
		$html = $field->build();
		
		expect($html)
			->toContain('<label')
			->toContain('Password');
	});

	test('PasswordField → includes help text when provided', function (): void {
		$field = new PasswordField($this->form, 'password', help: 'Choose a strong password');
		
		$html = $field->build();
		
		expect($html)
			->toContain('Choose a strong password')
			->toContain('class="help"');
	});

	test('PasswordField → applies required attribute to both inputs when required', function (): void {
		$field = new PasswordField($this->form, 'password', required: true);
		
		$html = $field->build();
		
		// Count occurrences of required attribute
		$requiredCount = substr_count($html, 'required');
		expect($requiredCount)->toBe(2);
	});

	test('PasswordField → applies disabled attribute to both inputs when disabled', function (): void {
		$field = new PasswordField($this->form, 'password', disabled: true);
		
		$html = $field->build();
		
		// Count occurrences of disabled attribute
		$disabledCount = substr_count($html, 'disabled');
		expect($disabledCount)->toBe(2);
	});

	test('PasswordField → includes placeholder when provided', function (): void {
		$field = new PasswordField($this->form, 'password', placeholder: 'Enter password');
		
		$html = $field->build();
		
		// Both inputs should have the same placeholder
		$placeholderCount = substr_count($html, 'placeholder="Enter password"');
		expect($placeholderCount)->toBe(2);
	});

	test('PasswordField → getValue returns correct value', function (): void {
		$field = new PasswordField($this->form, 'password', value: 'secret123');
		
		expect($field->getValue())->toBe('secret123');
	});

	test('PasswordField → disable method affects both inputs', function (): void {
		$field = new PasswordField($this->form, 'password');
		$field->disable();
		
		$html = $field->build();
		
		// Should have disabled and readonly on both inputs
		expect(substr_count($html, 'disabled'))->toBe(2);
		expect(substr_count($html, 'readonly'))->toBe(2);
	});

	test('PasswordField → applies custom CSS class', function (): void {
		$field = new PasswordField($this->form, 'password', class: 'custom-password');
		
		expect($field->build())
			->toContain('custom-password');
	});

	test('PasswordField → creates two form groups', function (): void {
		$field = new PasswordField($this->form, 'password');
		
		$html = $field->build();
		
		// Should contain two form-group divs
		$formGroupCount = substr_count($html, 'class="form-group"');
		expect($formGroupCount)->toBe(2);
	});

	test('PasswordField → includes icons when icon is enabled', function (): void {
		$field = new PasswordField($this->form, 'password', icon: true);
		
		$html = $field->build();
		
		// Should contain two form-group-icon divs (one for each input)
		$iconCount = substr_count($html, 'class="form-group-icon"');
		expect($iconCount)->toBe(2);
	});

	test('PasswordField → omits icons when icon is disabled', function (): void {
		$field = new PasswordField($this->form, 'password', icon: false);
		
		$html = $field->build();
		
		// Should not contain form-group-icon divs
		expect($html)->not->toContain('class="form-group-icon"');
	});

	test('PasswordField → applies minlength and maxlength to both inputs', function (): void {
		$field = new PasswordField($this->form, 'password', minlength: 8, maxlength: 32);
		
		$html = $field->build();
		
		expect(substr_count($html, 'minlength="8"'))->toBe(2);
		expect(substr_count($html, 'maxlength="32"'))->toBe(2);
	});
});