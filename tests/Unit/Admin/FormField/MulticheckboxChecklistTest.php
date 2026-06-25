<?php

use TotalCMS\Domain\Admin\FormField\MulticheckboxField;
use TotalCMS\Domain\Admin\TotalForm;

describe('MulticheckboxField checklist layout', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('checklist layout adds choice-field--checklist class and toggle-all button', function (): void {
		$field = new MulticheckboxField(
			$this->form,
			'tags',
			options: [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
			settings: ['layout' => 'checklist'],
		);

		$html = $field->build();

		expect($html)
			->toContain('choice-field--checklist')
			->toContain('choice-field-toggle-all');
	});

	test('default layout is byte-for-byte unchanged (no checklist class or toggle button)', function (): void {
		$field = new MulticheckboxField(
			$this->form,
			'tags',
			options: [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
		);

		$html = $field->build();

		expect($html)
			->not->toContain('choice-field--checklist')
			->not->toContain('choice-field-toggle-all');
	});
});
