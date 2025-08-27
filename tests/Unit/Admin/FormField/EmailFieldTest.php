<?php

use TotalCMS\Domain\Admin\FormField\EmailField;
use TotalCMS\Domain\Admin\TotalForm;

describe('EmailField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('EmailField → creates with email input type', function (): void {
		$field = new EmailField($this->form, 'email');

		expect($field->buildFormField())
			->toContain('type="email"')
			->toContain('name="email"');
	});

	test('EmailField → sets default field type to email', function (): void {
		$field = new EmailField($this->form, 'email');

		expect($field->build())
			->toContain('data-type="email"')
			->toContain('email-field');
	});

	test('EmailField → adds autocapitalize off attribute', function (): void {
		$field = new EmailField($this->form, 'email');

		expect($field->buildFormField())
			->toContain('autocapitalize="off"');
	});

	test('EmailField → builds complete form field with label', function (): void {
		$field = new EmailField($this->form, 'email', label: 'Email Address');

		$html = $field->build();

		expect($html)
			->toContain('<label')
			->toContain('Email Address')
			->toContain('type="email"')
			->toContain('autocapitalize="off"');
	});

	test('EmailField → handles value correctly', function (): void {
		$field = new EmailField($this->form, 'email', value: 'test@example.com');

		expect($field->buildFormField())
			->toContain('value="test@example.com"');
	});

	test('EmailField → applies required attribute when required', function (): void {
		$field = new EmailField($this->form, 'email', required: true);

		expect($field->buildFormField())
			->toContain('required');
	});

	test('EmailField → applies disabled attribute when disabled', function (): void {
		$field = new EmailField($this->form, 'email', disabled: true);

		expect($field->buildFormField())
			->toContain('disabled');
	});

	test('EmailField → includes help text when provided', function (): void {
		$field = new EmailField($this->form, 'email', help: 'Enter your email address');

		$html = $field->build();

		expect($html)
			->toContain('Enter your email address')
			->toContain('class="help"');
	});

	test('EmailField → includes placeholder when provided', function (): void {
		$field = new EmailField($this->form, 'email', placeholder: 'you@example.com');

		expect($field->buildFormField())
			->toContain('placeholder="you@example.com"');
	});

	test('EmailField → getValue returns correct value', function (): void {
		$field = new EmailField($this->form, 'email', value: 'user@test.com');

		expect($field->getValue())->toBe('user@test.com');
	});

	test('EmailField → disable method sets both disabled and readonly', function (): void {
		$field = new EmailField($this->form, 'email');
		$field->disable();

		$html = $field->buildFormField();

		expect($html)
			->toContain('disabled')
			->toContain('readonly');
	});

	test('EmailField → applies custom CSS class', function (): void {
		$field = new EmailField($this->form, 'email', class: 'custom-email');

		expect($field->build())
			->toContain('custom-email');
	});
});
