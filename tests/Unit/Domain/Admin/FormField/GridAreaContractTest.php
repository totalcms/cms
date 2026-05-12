<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\CheckboxField;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\MulticheckboxField;
use TotalCMS\Domain\Admin\FormField\RadioField;
use TotalCMS\Domain\Admin\FormField\ToggleField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * Locks in the CSS-variable grid-area contract introduced when we stopped
 * field wrappers from bleeding grid-area into non-formgrid contexts (dialogs,
 * deck tables). Every field must emit `style="--grid-area: ..."` and never a
 * bare `grid-area:` inline declaration — the CSS in `.formgrid > .form-field`
 * consumes the variable, and parents outside a formgrid stay inert.
 *
 * If this test fails, the property-field dialog layout bug will return:
 * see `css/forms/_layout.scss` and `FormField::buildFieldAttributes()`.
 */
describe('FormField grid-area contract', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '';
		$this->form->method('isEditMode')->willReturn(false);
	});

	dataset('fieldFactories', [
		'FormField'          => [fn ($form): FormField => new FormField($form, 'foo')],
		'CheckboxField'      => [fn ($form): CheckboxField => new CheckboxField($form, 'foo')],
		'ToggleField'        => [fn ($form): ToggleField => new ToggleField($form, 'foo')],
		'RadioField'         => [fn ($form): RadioField => new RadioField($form, 'foo', options: ['A'])],
		'MulticheckboxField' => [fn ($form): MulticheckboxField => new MulticheckboxField($form, 'foo', options: ['A'])],
	]);

	test('emits --grid-area CSS variable, never a bare grid-area declaration', function (Closure $factory): void {
		$field = $factory($this->form);
		$html  = $field->build();

		expect($html)->toContain('--grid-area: foo');

		// A bare `grid-area:` would re-introduce the dialog-form-field layout bug.
		// The leading space excludes the CSS-variable form (`--grid-area:`).
		expect($html)->not->toContain(' grid-area:');
	})->with('fieldFactories');
});
