<?php

use TotalCMS\Domain\Admin\FormField\MulticheckboxField;
use TotalCMS\Domain\Admin\TotalForm;

describe('MulticheckboxField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('MulticheckboxField → wrapper carries both multicheckbox-field and choice-field classes', function (): void {
		$field = new MulticheckboxField($this->form, 'choice', options: ['A']);

		expect($field->build())
			->toContain('multicheckbox-field')
			->toContain('choice-field');
	});

	test('MulticheckboxField → includes fieldGrid setting in style when provided', function (): void {
		$field = new MulticheckboxField($this->form, 'choice', settings: ['fieldGrid' => '250px'], options: ['A']);

		$html = $field->build();

		expect($html)->toContain('--fieldset-grid-size:250px');
	});

	test('MulticheckboxField → includes fieldColumns setting as column-width CSS var', function (): void {
		$field = new MulticheckboxField($this->form, 'choice', settings: ['fieldColumns' => '150px'], options: ['A']);

		$html = $field->build();

		expect($html)
			->toContain('--fieldset-columns:150px')
			->toContain('choice-field--columns');
	});

	test('MulticheckboxField → does not duplicate predefined options when value already matches', function (): void {
		$field = new MulticheckboxField(
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
