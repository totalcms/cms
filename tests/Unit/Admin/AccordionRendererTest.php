<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\AccordionRenderer;
use TotalCMS\Domain\Admin\FormGridBuilder;

function accordionPanel(string $title, string $formgrid = '', string $members = ''): array
{
	return ['title' => $title, 'inner' => new FormGridBuilder($formgrid), 'members' => $members];
}

describe('AccordionRenderer::render', function (): void {
	test('wraps panels in a group div carrying the grid area', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('One')], 'formgrid-accordion-1');

		expect($html)
			->toContain('class="formgrid-accordion"')
			->toContain('grid-area: formgrid-accordion-1;');
	});

	test('each panel is a details.cms-accordion.formgrid-panel with a summary', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('Content', '', '<div class="form-field">x</div>')]);

		expect($html)
			->toContain('<details')
			->toContain('cms-accordion')
			->toContain('formgrid-panel')
			->toContain('<summary>Content</summary>')
			->toContain('<div class="form-field">x</div>');
	});

	test('a group of one describes a closed resting state via data attributes', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('Advanced')]);

		expect($html)
			->toContain('data-open-first="false"')
			->toContain('data-solo-mode="false"');
	});

	test('a group of two or more describes a linked, first-open resting state via data attributes', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('A'), accordionPanel('B')]);

		expect($html)
			->toContain('data-open-first="true"')
			->toContain('data-solo-mode="true"');
	});

	test('escapes special characters in a panel title', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('<script>bad</script>')]);

		expect($html)->not->toContain('<script>bad</script>');
		expect($html)->toContain('&lt;script&gt;');
	});

	test('includes a scoped style tag when the panel has an inner grid', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('Grid', "a b\nc d")]);

		expect($html)->toContain('<style>');
		expect($html)->toContain("'a b'");
		expect($html)->not->toContain('container-type'); // nested grids must not re-declare the container
	});

	test('no inner grid: members are not wrapped in a .formgrid', function (): void {
		$field = '<div class="form-field" style="--grid-area: notes;">x</div>';
		$html  = (new AccordionRenderer())->render([accordionPanel('Bare', '', $field)]);

		expect($html)->not->toContain('class="formgrid"');
		expect($html)->toContain($field);
	});

	test('omits the grid-area style when gridArea is null', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('One')]);
		expect($html)->not->toContain('grid-area:');
	});

	test('appends extraClass alongside formgrid-accordion', function (): void {
		$html = (new AccordionRenderer())->render([accordionPanel('One')], null, 'my-extra');
		expect($html)->toContain('class="formgrid-accordion my-extra"');
	});

	test('renders nothing for an empty panel list', function (): void {
		expect((new AccordionRenderer())->render([]))->toBe('');
	});
});

describe('AccordionRenderer::wrap', function (): void {
	test('builds panels from the Twig-facing shape', function (): void {
		$html = (new AccordionRenderer())->wrap([
			['title' => 'Content', 'content' => '<p>body</p>', 'formgrid' => 'body body'],
			['title' => 'SEO',     'content' => '<p>seo</p>'],
		]);

		expect($html)
			->toContain('<summary>Content</summary>')
			->toContain('<summary>SEO</summary>')
			->toContain('<p>body</p>')
			->toContain('<p>seo</p>')
			->toContain('data-open-first="true"');
	});

	test('a panel with no title falls back to Section N', function (): void {
		$html = (new AccordionRenderer())->wrap([['content' => 'a'], ['content' => 'b']]);

		expect($html)->toContain('<summary>Section 1</summary>');
		expect($html)->toContain('<summary>Section 2</summary>');
	});
});

describe('TotalForm fieldContent with a formgrid accordion', function (): void {
	test('each field lands in its own panel, non-members stay outside', function (): void {
		$schema             = new TotalCMS\Domain\Schema\Data\SchemaData();
		$schema->formgrid   = "title\n>> Content\nbody body\n>> SEO\nseoTitle seoTitle\n<<";
		$schema->properties = [
			'title'    => ['type' => 'text', 'label' => 'Title'],
			'body'     => ['type' => 'text', 'label' => 'Body'],
			'seoTitle' => ['type' => 'text', 'label' => 'SEO Title'],
		];

		$form = (new ReflectionClass(TotalCMS\Domain\Admin\TotalForm::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'schemaData'))->setValue($form, $schema);
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'useFormGrid'))->setValue($form, true);
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'addOnly'))->setValue($form, false);

		$fields = [];
		foreach (['title', 'body', 'seoTitle'] as $name) {
			$mock = test()->getMockBuilder(TotalCMS\Domain\Admin\FormField\FormField::class)
				->disableOriginalConstructor()
				->onlyMethods(['build'])
				->getMock();
			$mock->method('build')->willReturn("<div class=\"field-$name\">$name</div>");
			$fields[$name] = $mock;
		}
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'fields'))->setValue($form, $fields);

		$html = (new ReflectionMethod(TotalCMS\Domain\Admin\TotalForm::class, 'fieldContent'))->invoke($form);

		// Split the two panels apart and check each holds only its own field.
		$panels = explode('<details', $html);
		expect($panels)->toHaveCount(3); // [before, panel 1, panel 2]

		expect($panels[1])->toContain('field-body')->not->toContain('field-seoTitle');
		expect($panels[2])->toContain('field-seoTitle')->not->toContain('field-body');

		// The outer field is outside the group entirely.
		expect($panels[0])->toContain('field-title');
	});

	test('a fieldset and an accordion in one formgrid both get their members', function (): void {
		$schema             = new TotalCMS\Domain\Schema\Data\SchemaData();
		$schema->formgrid   = "[[ Contact\nemail email\n]]\n>> Advanced\nslug slug\n<<";
		$schema->properties = [
			'email' => ['type' => 'text', 'label' => 'Email'],
			'slug'  => ['type' => 'text', 'label' => 'Slug'],
		];

		$form = (new ReflectionClass(TotalCMS\Domain\Admin\TotalForm::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'schemaData'))->setValue($form, $schema);
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'useFormGrid'))->setValue($form, true);
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'addOnly'))->setValue($form, false);

		$fields = [];
		foreach (['email', 'slug'] as $name) {
			$mock = test()->getMockBuilder(TotalCMS\Domain\Admin\FormField\FormField::class)
				->disableOriginalConstructor()
				->onlyMethods(['build'])
				->getMock();
			$mock->method('build')->willReturn("<div class=\"field-$name\">$name</div>");
			$fields[$name] = $mock;
		}
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'fields'))->setValue($form, $fields);

		$html = (new ReflectionMethod(TotalCMS\Domain\Admin\TotalForm::class, 'fieldContent'))->invoke($form);

		// This fixture has exactly one fieldset, so spanning strpos()..strrpos()
		// is safe; with two fieldsets the span would swallow everything between
		// them and the not->toContain assertions below would pass for the wrong
		// reason.
		$fieldsetStart = (int)strpos($html, '<fieldset');
		$fieldsetEnd   = (int)strrpos($html, '</fieldset>') + strlen('</fieldset>');
		$fieldsetHtml  = substr($html, $fieldsetStart, $fieldsetEnd - $fieldsetStart);

		expect($fieldsetHtml)->toContain('field-email')->not->toContain('field-slug');
		expect($html)->toContain('formgrid-accordion');

		// field-slug must not merely be absent from the fieldset — it must
		// actually render, inside the accordion group that opens with <details.
		$detailsStart = strpos($html, '<details');
		expect($detailsStart)->not->toBeFalse();
		$slugPos = strpos($html, 'field-slug');
		expect($slugPos)->not->toBeFalse();
		expect($slugPos)->toBeGreaterThan((int)$detailsStart);
	});

	test('a fieldset inside a panel renders inside that panel', function (): void {
		$schema             = new TotalCMS\Domain\Schema\Data\SchemaData();
		$schema->formgrid   = ">> Panel\nintro intro\n[[ Address\nstreet city\n]]\n<<";
		$schema->properties = [
			'intro'  => ['type' => 'text', 'label' => 'Intro'],
			'street' => ['type' => 'text', 'label' => 'Street'],
			'city'   => ['type' => 'text', 'label' => 'City'],
		];

		$form = (new ReflectionClass(TotalCMS\Domain\Admin\TotalForm::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'schemaData'))->setValue($form, $schema);
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'useFormGrid'))->setValue($form, true);
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'addOnly'))->setValue($form, false);

		$fields = [];
		foreach (['intro', 'street', 'city'] as $name) {
			$mock = test()->getMockBuilder(TotalCMS\Domain\Admin\FormField\FormField::class)
				->disableOriginalConstructor()
				->onlyMethods(['build'])
				->getMock();
			$mock->method('build')->willReturn("<div class=\"field-$name\">$name</div>");
			$fields[$name] = $mock;
		}
		(new ReflectionProperty(TotalCMS\Domain\Admin\TotalForm::class, 'fields'))->setValue($form, $fields);

		$html = (new ReflectionMethod(TotalCMS\Domain\Admin\TotalForm::class, 'fieldContent'))->invoke($form);

		// Nothing may escape to the top level — every field belongs to the panel.
		$panelStart = (int)strpos($html, '<details');
		expect(substr($html, 0, $panelStart))
			->not->toContain('field-intro')
			->not->toContain('field-street')
			->not->toContain('field-city');

		// intro must not merely be absent from before the panel — it must
		// actually render, and it must render inside the panel.
		$introPos = strpos($html, 'field-intro');
		expect($introPos)->not->toBeFalse();
		expect($introPos)->toBeGreaterThan($panelStart);

		// street and city belong to the fieldset; intro is the panel's own row.
		// This fixture has exactly one fieldset, so spanning strpos()..strrpos()
		// is safe; with two fieldsets the span would swallow everything between
		// them and the not->toContain assertion below would pass for the wrong
		// reason.
		$fieldsetStart = (int)strpos($html, '<fieldset');
		$fieldsetEnd   = (int)strrpos($html, '</fieldset>') + strlen('</fieldset>');
		$fieldsetHtml  = substr($html, $fieldsetStart, $fieldsetEnd - $fieldsetStart);

		expect($fieldsetHtml)->toContain('field-street')->toContain('field-city');
		expect($fieldsetHtml)->not->toContain('field-intro');

		// ...and the fieldset itself is inside the panel.
		expect($fieldsetStart)->toBeGreaterThan($panelStart);
	});
});
