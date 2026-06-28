<?php

use TotalCMS\Domain\Admin\FormField\ChecklistField;
use TotalCMS\Domain\Admin\FormField\RadioField;
use TotalCMS\Domain\Admin\TotalForm;

describe('Multicheckbox select-all toggle', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('multicheckbox renders the select-all toggle by default', function (): void {
		$field = new ChecklistField(
			$this->form,
			'tags',
			options: [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
		);

		expect($field->build())->toContain('choice-field-toggle-all');
	});

	test('toggleAll:false suppresses the toggle button', function (): void {
		$field = new ChecklistField(
			$this->form,
			'tags',
			options: [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
			settings: ['toggleAll' => false],
		);

		expect($field->build())->not->toContain('choice-field-toggle-all');
	});

	test('radio never renders a toggle button', function (): void {
		$field = new RadioField(
			$this->form,
			'choice',
			options: [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
		);

		expect($field->build())->not->toContain('choice-field-toggle-all');
	});
});
