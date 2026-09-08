<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\FileField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * A file field with no file behind it must still render schema-valid meta
 * values. `size` and `count` are integers in file.json; an empty input
 * becomes NaN → null in the admin payload and the save fails validation.
 * Deck items hand the field `''` when the stored item has no file at all.
 */
describe('FileField with no file', function (): void {
	beforeEach(function (): void {
		$self = $this;
		$this->captured = [];

		$this->form             = $this->createMock(TotalForm::class);
		$this->form->id         = '';
		$this->form->collection = 'test-collection';
		$this->form->api        = '/api';
		$this->form->method('isEditMode')->willReturn(false);
		$this->form->method('field')->willReturn('');
		$this->form->method('subField')
			->willReturnCallback(function (string $name, array $settings = []) use ($self): string {
				$self->captured[$name] = $settings['value'] ?? null;

				return '';
			});
	});

	test('renders size and count as 0 when the value is empty', function (mixed $value): void {
		$field = new FileField(form: $this->form, name: 'attachment', value: $value);
		$field->buildFormField();

		expect($this->captured['size'])->toBe(0)
			->and($this->captured['count'])->toBe(0);
	})->with([
		'deck item without the key' => [''],
		'empty array'               => [[]],
		'empty-string meta'         => [['size' => '', 'count' => '']],
		'null meta'                 => [['size' => null, 'count' => null]],
	]);

	test('casts numeric-string meta to integers', function (): void {
		$field = new FileField(form: $this->form, name: 'attachment', value: ['size' => '1024', 'count' => '3']);
		$field->buildFormField();

		expect($this->captured['size'])->toBe(1024)
			->and($this->captured['count'])->toBe(3);
	});
});
