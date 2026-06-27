<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\ChecklistField;
use TotalCMS\Domain\Admin\TotalForm;

beforeEach(function (): void {
	$this->form     = $this->createMock(TotalForm::class);
	$this->form->id = '123';
	// Watched field 'plan' = 'free'; conditions below expect 'premium' → inactive.
	$this->form->method('getFieldValue')->willReturn('free');
});

function visField(TotalForm $form, string $mode): string
{
	return (new ChecklistField($form, 'choice', settings: [
		'visibility' => ['watch' => 'plan', 'value' => 'premium', 'operator' => '==', 'mode' => $mode],
	], options: ['A']))->build();
}

test('an inactive disable-mode field renders field-disabled and stays visible', function (): void {
	$html = visField($this->form, 'disable');
	expect($html)->toContain('field-disabled');
	expect($html)->not->toContain('field-hidden');
	expect($html)->not->toContain('display: none');
});

test('an inactive hide-mode field still hides (unchanged behaviour)', function (): void {
	$html = visField($this->form, 'hide');
	expect($html)->toContain('field-hidden');
	expect($html)->toContain('display: none');
	expect($html)->not->toContain('field-disabled');
});

test('an inactive disable-mode field locks its input server-side (disabled attribute)', function (): void {
	// Without the server-side lock the field would be greyed but keyboard-editable
	// until JS runs; the input must carry the disabled attribute from initial render.
	$html = visField($this->form, 'disable');
	expect($html)->toMatch('/<input[^>]*\bdisabled/');
});

test('an ACTIVE disable-mode field is not disabled', function (): void {
	$form     = $this->createMock(TotalForm::class);
	$form->id = '123';
	$form->method('getFieldValue')->willReturn('premium'); // == value → condition met → active
	$html = (new ChecklistField($form, 'choice', settings: [
		'visibility' => ['watch' => 'plan', 'value' => 'premium', 'operator' => '==', 'mode' => 'disable'],
	], options: ['A']))->build();
	expect($html)->toContain('field-visible');
	expect($html)->not->toMatch('/<input[^>]*\bdisabled/');
});
