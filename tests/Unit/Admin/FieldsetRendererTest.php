<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FieldsetRenderer;
use TotalCMS\Domain\Admin\FormGridBuilder;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Schema\Data\SchemaData;

// ---------------------------------------------------------------------------
// FieldsetRenderer unit tests
// ---------------------------------------------------------------------------

describe('FieldsetRenderer::render', function (): void {
	test('renders a styled fieldset with legend, scoped grid style and members', function (): void {
		$inner = new FormGridBuilder('a b');
		$html  = (new FieldsetRenderer())->render('Contact', '<div class="form-field">x</div>', $inner, '');

		expect($html)
			->toContain('<fieldset')
			->toContain('form-grid-fieldset')
			->toContain('<legend>Contact</legend>')
			->toContain('class="formgrid"')           // the nested grid div
			->toContain('<div class="form-field">x</div>');
	});

	test('omits the legend element when legend is null', function (): void {
		$html = (new FieldsetRenderer())->render(null, 'X', new FormGridBuilder(''), '');
		expect($html)->not->toContain('<legend>');
	});

	test('omits the legend element when legend is empty string', function (): void {
		$html = (new FieldsetRenderer())->render('', 'X', new FormGridBuilder(''), '');
		expect($html)->not->toContain('<legend>');
	});

	test('adds grid-area style when gridArea is provided', function (): void {
		$html = (new FieldsetRenderer())->render('Grp', 'Y', new FormGridBuilder(''), '', 'formgrid-fieldset-1');
		expect($html)->toContain('grid-area: formgrid-fieldset-1;');
	});

	test('omits grid-area style when gridArea is null', function (): void {
		$html = (new FieldsetRenderer())->render('Grp', 'Y', new FormGridBuilder(''), '', null);
		expect($html)->not->toContain('grid-area:');
	});

	test('appends extraClass alongside form-grid-fieldset', function (): void {
		$html = (new FieldsetRenderer())->render(null, '', new FormGridBuilder(''), 'my-extra');
		expect($html)->toContain('class="form-grid-fieldset my-extra"');
	});

	test('includes scoped style tag when inner grid has layout', function (): void {
		$inner = new FormGridBuilder("a b\nc d");
		$html  = (new FieldsetRenderer())->render('X', '', $inner, '');
		expect($html)->toContain('<style>');
		// Container-type must NOT be in the nested style tag
		expect($html)->not->toContain('container-type');
	});

	test('omits style tag when inner grid is empty', function (): void {
		$html = (new FieldsetRenderer())->render('X', '', new FormGridBuilder(''), '');
		expect($html)->not->toContain('<style>');
	});

	test('no inner grid: members are not wrapped in a .formgrid (no stray grid-area placement)', function (): void {
		$field = '<div class="form-field" style="--grid-area: schemas;">x</div>';
		$html  = (new FieldsetRenderer())->render('Schemas', $field, new FormGridBuilder(''), '');
		// The field must render, but NOT inside a `.formgrid` (which would apply its --grid-area)
		expect($html)
			->not->toContain('class="formgrid"')
			->toContain($field);
	});

	test('escapes special characters in legend', function (): void {
		$html = (new FieldsetRenderer())->render('<script>bad</script>', '', new FormGridBuilder(''), '');
		expect($html)->not->toContain('<script>bad</script>');
		expect($html)->toContain('&lt;script&gt;');
	});
});

describe('FieldsetRenderer::wrap', function (): void {
	test('builds inner FormGridBuilder from formgrid string', function (): void {
		$html = (new FieldsetRenderer())->wrap('My Legend', '<p>field</p>', 'x y', '');
		expect($html)
			->toContain('<fieldset')
			->toContain('<legend>My Legend</legend>')
			->toContain('<p>field</p>');
	});

	test('wrap with empty formgrid string still renders fieldset', function (): void {
		$html = (new FieldsetRenderer())->wrap(null, '<p>field</p>', '', '');
		expect($html)->toContain('<fieldset')->toContain('<p>field</p>');
	});

	test('cms.form.fieldset renders legend + card around captured content', function (): void {
		$html = (new FieldsetRenderer())->wrap('Address', '<div class="form-field">x</div>', "street street\ncity zip", '');
		expect($html)
			->toContain('form-grid-fieldset')
			->toContain('<legend>Address</legend>')
			->toContain('class="formgrid"')
			->toContain("'street street'"); // inner grid style present
	});
});

// ---------------------------------------------------------------------------
// FormGridBuilder::ensureFieldsIncluded — fieldset-member exclusion
// ---------------------------------------------------------------------------

describe('FormGridBuilder::ensureFieldsIncluded with fieldset members', function (): void {
	test('fieldset members are not appended as outer rows', function (): void {
		// outer grid has 'id' and 'title'; a [[ ]] block owns 'email' and 'phone'
		$b = new FormGridBuilder("id title\n[[\nemail phone\n]]");

		// calling ensureFieldsIncluded with all four names must not add email/phone as outer rows
		$b->ensureFieldsIncluded(['id', 'title', 'email', 'phone']);

		$style = $b->toStyleTag('form-test');

		// The outer grid should still have 'id title'
		expect($style)->toContain("'id title'");
		// email and phone must NOT appear as extra outer rows
		expect($style)->not->toContain("'email email'");
		expect($style)->not->toContain("'phone phone'");
	});

	test('non-member fields not in grid are still appended as outer rows', function (): void {
		$b = new FormGridBuilder("id title\n[[\nemail phone\n]]");

		$b->ensureFieldsIncluded(['id', 'title', 'email', 'phone', 'notes']);

		$style = $b->toStyleTag('form-test');

		// 'notes' is not a fieldset member and not an outer row — it should be appended
		expect($style)->toContain("'notes notes'");
	});
});

// ---------------------------------------------------------------------------
// Integration: TotalForm with a [[ ]] block renders members inside <fieldset>
// ---------------------------------------------------------------------------

describe('TotalForm fieldContent with formgrid fieldset', function (): void {
	test('member fields render inside fieldset, non-members render outside', function (): void {
		$schema = new SchemaData();
		// 'title' is an outer field; 'email' and 'phone' are fieldset members
		$schema->formgrid = "title\n[[ Contact\nemail phone\n]]";
		$schema->properties = [
			'title' => ['type' => 'text', 'label' => 'Title'],
			'email' => ['type' => 'text', 'label' => 'Email'],
			'phone' => ['type' => 'text', 'label' => 'Phone'],
		];

		$form = (new ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();

		// Wire the minimum state fieldContent() needs
		(new ReflectionProperty(TotalForm::class, 'schemaData'))->setValue($form, $schema);
		(new ReflectionProperty(TotalForm::class, 'useFormGrid'))->setValue($form, true);
		(new ReflectionProperty(TotalForm::class, 'addOnly'))->setValue($form, false);

		// Build real FormField stubs that produce identifiable HTML
		$fields = [];
		foreach (['title', 'email', 'phone'] as $name) {
			$mock = test()->getMockBuilder(\TotalCMS\Domain\Admin\FormField\FormField::class)
				->disableOriginalConstructor()
				->onlyMethods(['build'])
				->getMock();
			$mock->method('build')->willReturn("<div class=\"field-$name\">$name</div>");
			$fields[$name] = $mock;
		}
		(new ReflectionProperty(TotalForm::class, 'fields'))->setValue($form, $fields);

		$html = (new ReflectionMethod(TotalForm::class, 'fieldContent'))->invoke($form);

		// The fieldset wrapper must be present
		expect($html)->toContain('form-grid-fieldset');
		expect($html)->toContain('<legend>Contact</legend>');

		// email and phone must be INSIDE the fieldset
		$fieldsetStart = strpos($html, '<fieldset');
		$fieldsetEnd   = strrpos($html, '</fieldset>');
		$fieldsetHtml  = substr($html, (int)$fieldsetStart, (int)$fieldsetEnd - (int)$fieldsetStart + strlen('</fieldset>'));

		expect($fieldsetHtml)->toContain('field-email');
		expect($fieldsetHtml)->toContain('field-phone');

		// title must be OUTSIDE the fieldset (before or after it)
		$beforeFieldset = substr($html, 0, (int)$fieldsetStart);
		$afterFieldset  = substr($html, (int)$fieldsetEnd + strlen('</fieldset>'));
		$outsideHtml    = $beforeFieldset . $afterFieldset;

		expect($outsideHtml)->toContain('field-title');
		// title must NOT appear inside the fieldset
		expect($fieldsetHtml)->not->toContain('field-title');
	});
});
