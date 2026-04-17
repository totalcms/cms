<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\ColorField;
use TotalCMS\Domain\Admin\FormField\PriceField;
use TotalCMS\Domain\Admin\FormField\RangeField;
use TotalCMS\Domain\Admin\FormField\StyledtextField;
use TotalCMS\Domain\Admin\FormField\UrlField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * Smoke coverage for the thin FormField subclasses that had 0% coverage.
 * Each class is a small specialisation of FormField (or TextareaField) — the
 * test set verifies the field-specific behaviour documented in each class
 * (step=0.01 for Price, hex extraction for Color, autocapitalize off for Url,
 * wrapper markup for Styledtext, range-value element for Range).
 */
describe('Simple form fields', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '';
		$this->form->method('isEditMode')->willReturn(false);
	});

	// --- ColorField ---

	test('ColorField → empty value becomes null', function (): void {
		$field = new ColorField(form: $this->form, name: 'accent', value: '');

		$ref = new ReflectionProperty($field, 'value');
		expect($ref->getValue($field))->toBeNull();
	});

	test('ColorField → array value is reduced to its hex key', function (): void {
		$field = new ColorField(
			form : $this->form,
			name : 'accent',
			value: ['hex' => '#ff0000', 'rgb' => [255, 0, 0]],
		);

		$ref = new ReflectionProperty($field, 'value');
		expect($ref->getValue($field))->toBe('#ff0000');
	});

	test('ColorField → string value passes through unchanged', function (): void {
		$field = new ColorField(form: $this->form, name: 'accent', value: '#123456');

		$ref = new ReflectionProperty($field, 'value');
		expect($ref->getValue($field))->toBe('#123456');
	});

	test('ColorField → build() renders a color input with the hex value', function (): void {
		$field = new ColorField(form: $this->form, name: 'accent', value: '#abcdef');
		$html  = $field->build();

		expect($html)->toContain('type="color"');
		expect($html)->toContain('value="#abcdef"');
		expect($html)->toContain('name="accent"');
	});

	// --- PriceField ---

	test('PriceField → pins step to 0.01 in settings', function (): void {
		$field = new PriceField(form: $this->form, name: 'price');

		$settings = (new ReflectionProperty($field, 'settings'))->getValue($field);
		expect($settings['step'])->toBe(0.01);

		$step = (new ReflectionProperty($field, 'step'))->getValue($field);
		expect($step)->toBe(0.01);
	});

	test('PriceField → overrides caller-supplied step', function (): void {
		$field = new PriceField(form: $this->form, name: 'price', settings: ['step' => 1]);

		$settings = (new ReflectionProperty($field, 'settings'))->getValue($field);
		expect($settings['step'])->toBe(0.01);
	});

	test('PriceField → build() renders a number input', function (): void {
		$field = new PriceField(form: $this->form, name: 'price', value: '9.99');
		$html  = $field->build();

		expect($html)->toContain('type="number"');
		expect($html)->toContain('price-field');
	});

	// --- RangeField ---

	test('RangeField → build() renders input plus range-value element', function (): void {
		$field = new RangeField(
			form : $this->form,
			name : 'volume',
			value: 42,
			min  : 0,
			max  : 100,
		);
		$html = $field->build();

		expect($html)->toContain('type="range"');
		expect($html)->toContain('class="range-value"');
		expect($html)->toContain('>42</div>');
	});

	test('RangeField → has no icon', function (): void {
		$field = new RangeField(form: $this->form, name: 'volume');
		$icon  = (new ReflectionProperty($field, 'icon'))->getValue($field);

		expect($icon)->toBeFalse();
	});

	// --- UrlField ---

	test('UrlField → includes autocapitalize off in the input attributes', function (): void {
		$field = new UrlField(form: $this->form, name: 'website', value: 'https://example.com');
		$html  = $field->build();

		expect($html)->toContain('type="url"');
		expect($html)->toContain('autocapitalize="off"');
	});

	// --- StyledtextField ---

	test('StyledtextField → wraps textarea in .styledtext-wrapper div', function (): void {
		$field = new StyledtextField(form: $this->form, name: 'body', value: '<p>hi</p>');
		$html  = $field->build();

		expect($html)->toContain('styledtext-wrapper');
		expect($html)->toContain('<textarea');
		expect($html)->toContain('<p>hi</p>');
	});

	test('StyledtextField → renders with the styledtext-field class', function (): void {
		$field = new StyledtextField(form: $this->form, name: 'body');
		$html  = $field->build();

		expect($html)->toContain('styledtext-field');
	});
});
