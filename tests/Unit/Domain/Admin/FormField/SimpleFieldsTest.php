<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\ColorField;
use TotalCMS\Domain\Admin\FormField\ListField;
use TotalCMS\Domain\Admin\FormField\MarkdownField;
use TotalCMS\Domain\Admin\FormField\PriceField;
use TotalCMS\Domain\Admin\FormField\RangeField;
use TotalCMS\Domain\Admin\FormField\StyledmarkdownField;
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

	// --- ListField with grouped options ---

	test('ListField → a grouped option source renders its optgroups on a new record', function (): void {
		// `propertyOptions: podcastCategories` returns a grouped map (parent =>
		// options) for <optgroup>s. The selected-first reorder only knew flat
		// lists and dropped every group — and it ran for a new record too,
		// because an empty string is not an empty array — so the podcast
		// show's Categories picker came up with nothing to choose from.
		$html = (new ListField(form: $this->form, name: 'categories', value: '', settings: ['propertyOptions' => 'podcastCategories']))->build();

		expect($html)->toContain('<optgroup label="Arts">')
			->toContain('<option value="Arts &gt; Books"')
			->toContain('<optgroup label="Technology">');
		expect(substr_count($html, '<option'))->toBeGreaterThan(100);
	});

	test('ListField → grouped options keep their groups when values are selected', function (): void {
		$html = (new ListField(form: $this->form, name: 'categories', value: ['Technology', 'Arts > Books'], settings: ['propertyOptions' => 'podcastCategories']))->build();

		expect($html)->toContain('<optgroup label="Arts">')
			->toMatch('~<option value="Technology"[^>]*selected~')
			->toMatch('~<option value="Arts &gt; Books"[^>]*selected~');
	});

	// --- ColorField ---

	test('ColorField → empty value becomes null', function (): void {
		$field = new ColorField(form: $this->form, name: 'accent', value: '');

		$ref = new ReflectionProperty($field, 'value');
		expect($ref->getValue($field))->toBeNull();
	});

	test('ColorField → a clearable field renders a clear button and marks an empty value', function (): void {
		$this->form->method('t')->willReturnCallback(static fn (string $key, string $default = ''): string => $default);

		$empty = (new ColorField(form: $this->form, name: 'accent', value: '', settings: ['clearable' => true]))->build();
		expect($empty)->toContain('class="form-field color-field  color-clearable color-empty"')
			->toContain('<button type="button" class="color-clear"')
			->toContain('No color');

		$set = (new ColorField(form: $this->form, name: 'accent', value: ['hex' => '#ff0000'], settings: ['clearable' => true]))->build();
		expect($set)->toContain('color-clearable')->not->toContain('color-empty');
	});

	test('ColorField → without the setting there is no clear button', function (): void {
		$html = (new ColorField(form: $this->form, name: 'accent', value: ''))->build();
		expect($html)->not->toContain('color-clear')->not->toContain('color-clearable');
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

	test('PriceField → build() renders a text input', function (): void {
		$field = new PriceField(form: $this->form, name: 'price', value: '9.99');
		$html  = $field->build();

		expect($html)->toContain('type="text"');
		expect($html)->not->toContain('type="number"');
		expect($html)->toContain('price-field');
	});

	test('PriceField → does not emit a step attribute', function (): void {
		$field = new PriceField(form: $this->form, name: 'price');

		$html     = $field->build();
		$settings = (new ReflectionProperty($field, 'settings'))->getValue($field);

		expect($html)->not->toContain('step=');
		expect($settings)->not->toHaveKey('step');
	});

	test('PriceField → site locale is empty so no locale injected into settings', function (): void {
		// The mock returns '' for getDefaultLocale (PHPUnit default for string return)
		$field    = new PriceField(form: $this->form, name: 'price');
		$settings = (new ReflectionProperty($field, 'settings'))->getValue($field);

		expect($settings)->not->toHaveKey('locale');
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

	// --- StyledmarkdownField ---

	test('StyledmarkdownField → wraps textarea in the shared editor wrapper', function (): void {
		$field = new StyledmarkdownField(form: $this->form, name: 'body', value: "# Hi\n\n* one");
		$html  = $field->build();

		expect($html)->toContain('styledtext-wrapper markdown-wrapper');
		expect($html)->toContain('<textarea');
		expect($html)->toContain('# Hi');
	});

	test('StyledmarkdownField → renders with the styledmarkdown-field class and type', function (): void {
		$field = new StyledmarkdownField(form: $this->form, name: 'body');
		$html  = $field->build();

		expect($html)->toContain('styledmarkdown-field');
		expect($html)->toContain('data-type="styledmarkdown"');
	});

	test('StyledmarkdownField → escapes HTML in the stored markdown', function (): void {
		$field = new StyledmarkdownField(form: $this->form, name: 'body', value: '</textarea><script>x</script>');
		$html  = $field->build();

		expect($html)->not->toContain('</textarea><script>');
		expect($html)->toContain('&lt;/textarea&gt;&lt;script&gt;');
	});

	test('StyledmarkdownField → an entity in the markdown survives the form round trip', function (): void {
		$field = new StyledmarkdownField(form: $this->form, name: 'body', value: 'Fish &amp; chips &copy;');
		$html  = $field->build();

		// The browser decodes this back to the stored text.
		expect($html)->toContain('Fish &amp;amp; chips &amp;copy;');
	});

	// --- MarkdownField ---

	test('MarkdownField → wraps textarea in the shared editor wrapper', function (): void {
		$field = new MarkdownField(form: $this->form, name: 'body', value: "# Hi\n\n* one");
		$html  = $field->build();

		expect($html)->toContain('styledtext-wrapper markdown-wrapper');
		expect($html)->toContain('<textarea');
		expect($html)->toContain('# Hi');
	});

	test('MarkdownField → renders with the markdown-field class and type', function (): void {
		$field = new MarkdownField(form: $this->form, name: 'body');
		$html  = $field->build();

		expect($html)->toContain('markdown-field');
		expect($html)->toContain('data-type="markdown"');
		expect($html)->not->toContain('styledmarkdown');
	});

	test('MarkdownField → escapes HTML in the stored markdown', function (): void {
		$field = new MarkdownField(form: $this->form, name: 'body', value: '</textarea><script>x</script>');
		$html  = $field->build();

		expect($html)->not->toContain('</textarea><script>');
		expect($html)->toContain('&lt;/textarea&gt;&lt;script&gt;');
	});

	test('MarkdownField → keeps a leading newline in the value', function (): void {
		// The HTML parser drops one newline straight after <textarea>, so a
		// value that begins with one would lose it on an untouched save.
		$field = new MarkdownField(form: $this->form, name: 'body', value: "\nabc");

		expect($field->build())->toContain(">\n\nabc</textarea>");
	});

	test('StyledmarkdownField → is the markdown field with its own type', function (): void {
		$field = new StyledmarkdownField(form: $this->form, name: 'body');

		expect($field)->toBeInstanceOf(MarkdownField::class);
		expect($field->build())->toContain('data-type="styledmarkdown"');
	});
});
