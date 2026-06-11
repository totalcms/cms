<?php

use TotalCMS\Domain\Admin\FormField\PriceField;
use TotalCMS\Domain\Admin\TotalForm;

describe('PriceField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
		$this->form->method('getDefaultLocale')->willReturn('en-US');
	});

	test('PriceField → renders a text input, not a number input', function (): void {
		$field = new PriceField($this->form, 'price');
		$html  = $field->build();

		expect($html)
			->toContain('type="text"')
			->not->toContain('type="number"')
			->toContain('data-type="price"')
			->toContain('inputmode="decimal"');
	});

	test('PriceField → uses a numeric keypad when decimals is 0', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['decimals' => 0]);
		$html  = $field->build();

		expect($html)
			->toContain('inputmode="numeric"')
			->not->toContain('inputmode="decimal"');
	});

	test('PriceField → injects the site default locale into data-settings', function (): void {
		$field = new PriceField($this->form, 'price');
		$html  = $field->build();

		expect($html)->toContain('&quot;locale&quot;:&quot;en-US&quot;');
	});

	test('PriceField → an explicit locale setting wins over the site default', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['locale' => 'de-DE']);
		$html  = $field->build();

		expect($html)->toContain('de-DE');
		expect($html)->not->toContain('en-US');
	});

	test('PriceField → resolves currency from the locale when none is given', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['locale' => 'de-DE']);
		$html  = $field->build();

		expect($html)->toContain('EUR');
	});

	test('PriceField → uppercases an explicit currency setting', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'gbp']);
		$html  = $field->build();

		expect($html)->toContain('GBP');
	});

	test('PriceField → resolves the standard decimals for the currency', function (): void {
		$field = new PriceField($this->form, 'price');
		$html  = $field->build();

		// USD → 2 decimals, serialized into the escaped data-settings JSON.
		expect($html)
			->toContain('decimals')
			->toContain('2');
	});

	test('PriceField → resolves zero decimals for JPY', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['locale' => 'ja-JP', 'currency' => 'JPY']);
		$html  = $field->build();

		expect($html)->toContain('&quot;decimals&quot;:0');
	});

	test('PriceField → appends a dollar icon class for USD', function (): void {
		$field = new PriceField($this->form, 'price');

		expect($field->build())->toContain('icon-dollar');
	});

	test('PriceField → appends a euro icon class for EUR', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'EUR', 'locale' => 'de-DE']);

		expect($field->build())->toContain('icon-euro');
	});

	test('PriceField → appends a pound icon class for GBP', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'GBP', 'locale' => 'en-GB']);

		expect($field->build())->toContain('icon-pound');
	});

	test('PriceField → appends a yen icon class for JPY', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'JPY', 'locale' => 'ja-JP']);

		expect($field->build())->toContain('icon-yen');
	});

	test('PriceField → appends no specific icon class for a non-glyph currency (uses the .price-field default)', function (): void {
		// INR (₹) has no dedicated glyph icon, so no icon-* class is appended —
		// the .price-field CSS default (icon-currency) renders instead.
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'INR', 'locale' => 'en-IN']);

		$html = $field->build();

		expect($html)
			->not->toContain('icon-dollar')
			->not->toContain('icon-euro')
			->not->toContain('icon-pound')
			->not->toContain('icon-yen');
	});

	test('PriceField → an operator icon class wins over the resolved currency icon', function (): void {
		$field = new PriceField($this->form, 'price', class: 'icon-euro', settings: ['currency' => 'USD']);
		$html  = $field->build();

		expect($html)
			->toContain('icon-euro')
			->not->toContain('icon-dollar');
	});

	test('PriceField → does not emit a number step attribute', function (): void {
		$field = new PriceField($this->form, 'price');
		expect($field->build())->not->toContain('step="0.01"');
	});

	test('PriceField → server-renders the currency symbol span for USD', function (): void {
		$field = new PriceField($this->form, 'price');
		$html  = $field->build();

		expect($html)
			->toContain('totalform-currency-symbol')
			->toContain('$');
	});

	test('PriceField → server-renders the euro symbol for EUR', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'EUR', 'locale' => 'de-DE']);

		expect($field->build())->toContain('€');
	});

	test('PriceField → server-renders the yen symbol for JPY', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'JPY', 'locale' => 'ja-JP']);

		expect($field->build())->toMatch('/[¥￥]/u');
	});

	test('PriceField → server-renders the pound symbol for GBP', function (): void {
		$field = new PriceField($this->form, 'price', settings: ['currency' => 'GBP', 'locale' => 'en-GB']);

		expect($field->build())->toContain('£');
	});
});
