<?php

use TotalCMS\Domain\Admin\FormField\ChecklistField;
use TotalCMS\Domain\Admin\FormField\ChoiceField;
use TotalCMS\Domain\Admin\TotalForm;

describe('Field type resolution contract', function (): void {
	test('canonicalFieldType maps the multicheckbox alias to checklist', function (): void {
		expect(TotalForm::canonicalFieldType('multicheckbox'))->toBe('checklist');
		expect(TotalForm::canonicalFieldType('checklist'))->toBe('checklist');
		expect(TotalForm::canonicalFieldType('text'))->toBe('text');
	});

	test('checklist resolves to ChecklistField', function (): void {
		expect(TotalForm::resolveFieldClass('checklist'))
			->toBe(ChecklistField::class);
	});

	test('multicheckbox alias resolves to ChecklistField (back-compat)', function (): void {
		expect(TotalForm::resolveFieldClass('multicheckbox'))
			->toBe(ChecklistField::class);
	});

	test('multicheckbox resolves to a ChoiceField subclass that renders checkboxes', function (): void {
		$class = TotalForm::resolveFieldClass('multicheckbox');
		expect(is_subclass_of($class, ChoiceField::class))->toBeTrue();

		$form     = $this->createMock(TotalForm::class);
		$form->id = 1;
		$field    = new $class($form, 'tags', options: [
			['value' => 'a', 'label' => 'A'],
			['value' => 'b', 'label' => 'B'],
		]);

		expect((string)$field->build())
			->toContain('type="checkbox"')
			->toContain('name="tags"');
	});
});
