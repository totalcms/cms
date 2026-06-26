<?php

use TotalCMS\Domain\Admin\FormField\ChecklistField;
use TotalCMS\Domain\Admin\TotalForm;

describe('ChecklistField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('ChecklistField → wrapper carries both checklist-field and choice-field classes', function (): void {
		$field = new ChecklistField($this->form, 'choice', options: ['A']);

		expect($field->build())
			->toContain('checklist-field')
			->toContain('choice-field');
	});

	test('ChecklistField → includes fieldGrid setting in style when provided', function (): void {
		$field = new ChecklistField($this->form, 'choice', settings: ['fieldGrid' => '250px'], options: ['A']);

		$html = $field->build();

		expect($html)->toContain('--fieldset-grid-size:250px');
	});

	test('ChecklistField → includes fieldColumns setting as column-width CSS var', function (): void {
		$field = new ChecklistField($this->form, 'choice', settings: ['fieldColumns' => '150px'], options: ['A']);

		$html = $field->build();

		expect($html)
			->toContain('--fieldset-columns:150px')
			->toContain('choice-field--columns');
	});

	test('ChecklistField → option inputs use a bare name (no []) — the Sync Manager JS relies on this', function (): void {
		// Each checkbox option renders name="schemas", NOT name="schemas[]". The
		// Sync Manager serializes its selection by reading input[name="schemas"]:checked,
		// so a bracketed name here would break specific-selection sync (only "All"
		// would work). Guards against reintroducing that mismatch.
		$field = new ChecklistField($this->form, 'schemas', options: [
			['value' => 'blog', 'label' => 'blog'],
			['value' => 'page', 'label' => 'page'],
		]);

		$html = $field->build();

		expect($html)
			->toContain('name="schemas"')
			->not->toContain('name="schemas[]"');
	});

	test('ChecklistField → does not duplicate predefined options when value already matches', function (): void {
		$field = new ChecklistField(
			$this->form,
			'ops',
			value: ['read', 'update'],
			options: [
				['value' => 'create', 'label' => 'Create'],
				['value' => 'read',   'label' => 'Read'],
				['value' => 'update', 'label' => 'Update'],
				['value' => 'delete', 'label' => 'Delete'],
			],
		);

		$html = $field->build();

		expect(substr_count($html, 'value="read"'))->toBe(1);
		expect(substr_count($html, 'value="update"'))->toBe(1);
	});
});
