<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\CodeField;
use TotalCMS\Domain\Admin\FormField\StyledtextField;
use TotalCMS\Domain\Admin\FormField\TextareaField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * A plain textarea sizes to its content in CSS (`field-sizing: content`),
 * floored at its `rows`. The field marks itself with `data-autosize` and
 * hands the row count to the stylesheet as `--rows`. Fields that give their
 * textarea to an editor (code, styled text) are left alone, and
 * `autoGrow: false` keeps a fixed height.
 */
describe('TextareaField sizing', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '';
		$this->form->method('isEditMode')->willReturn(false);
	});

	function textareaTag(string $html): string
	{
		preg_match('/<textarea[^>]*>/', $html, $m);

		return $m[0] ?? '';
	}

	test('a plain textarea sizes to its content, floored at its rows', function (): void {
		$tag = textareaTag((new TextareaField(form: $this->form, name: 'summary', settings: ['rows' => 5]))->build());

		expect($tag)->toContain('data-autosize')
			->toContain('rows="5"')
			->toContain('style="--rows:5"');
	});

	test('without a rows setting the floor is the default eight', function (): void {
		$tag = textareaTag((new TextareaField(form: $this->form, name: 'summary'))->build());

		expect($tag)->toContain('rows="8"')->toContain('style="--rows:8"');
	});

	test('autoGrow false keeps a fixed height', function (): void {
		$tag = textareaTag((new TextareaField(form: $this->form, name: 'summary', settings: ['rows' => 3, 'autoGrow' => false]))->build());

		expect($tag)->toContain('rows="3"')
			->not->toContain('data-autosize')
			->not->toContain('--rows');
	});

	test('an operator style attribute is kept alongside the row count', function (): void {
		$tag = textareaTag((new TextareaField(form: $this->form, name: 'summary', settings: ['rows' => 4, 'attributes' => ['style' => 'font-family:monospace']]))->build());

		expect($tag)->toContain('style="--rows:4;font-family:monospace"');
	});

	test('fields that hand their textarea to an editor do not autosize', function (): void {
		foreach ([new CodeField(form: $this->form, name: 'css'), new StyledtextField(form: $this->form, name: 'body')] as $field) {
			expect(textareaTag($field->build()))->not->toContain('data-autosize')->not->toContain('--rows');
		}
	});
});
