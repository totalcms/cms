<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\LocalizedstyledtextField;
use TotalCMS\Domain\Admin\FormField\LocalizedtextareaField;
use TotalCMS\Domain\Admin\FormField\LocalizedtextField;
use TotalCMS\Domain\Admin\TotalForm;

describe('LocalizedtextField rendering', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '123';
		$this->form->method('getLocales')->willReturn([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
			['code' => 'de',    'label' => 'Deutsch',      'dir' => 'ltr'],
			['code' => 'ar',    'label' => 'العربية',      'dir' => 'rtl'],
		]);
		$this->form->method('getDefaultLocale')->willReturn('en_US');
	});

	test('emits dir="rtl" on the input of an RTL locale pane', function (): void {
		$field = new LocalizedtextField($this->form, 'title');

		$html = $field->build();

		// The Arabic pane must carry dir="rtl" on its input. LTR locales
		// can be either implicit or explicit — only the RTL assertion is
		// load-bearing.
		expect($html)->toMatch('/data-locale="ar"[^>]*dir="rtl"|dir="rtl"[^>]*data-locale="ar"/');
	});

	test('emits one tab per configured locale, defaultLocale active', function (): void {
		$field = new LocalizedtextField($this->form, 'title');

		$html = $field->build();

		expect($html)->toContain('data-locale-tab="en_US"');
		expect($html)->toContain('data-locale-tab="de"');
		expect($html)->toContain('data-locale-tab="ar"');
		expect($html)->toContain('aria-selected="true"');
	});

	test('renders hidden name-carrier so the property name reaches form serialization', function (): void {
		// Regression guard: without this input, every localized field
		// serializes under an empty key in the form payload (see the bug
		// fix earlier in 3.5 sliver development).
		$field = new LocalizedtextField($this->form, 'title');

		$html = $field->build();

		expect($html)->toMatch('/<input[^>]+type="hidden"[^>]+name="title"|<input[^>]+name="title"[^>]+type="hidden"/');
	});

	test('renders the configuration-missing error when no locales are configured', function (): void {
		$form     = $this->createMock(TotalForm::class);
		$form->id = '123';
		$form->method('getLocales')->willReturn([]);
		$form->method('getDefaultLocale')->willReturn('');

		$field = new LocalizedtextField($form, 'title');

		expect($field->build())
			->toContain('localized-field-error')
			->toContain('locales');
	});

	test('populates per-locale inputs with stored values', function (): void {
		$field = new LocalizedtextField($this->form, 'title', value: [
			'en_US' => 'Hello',
			'de'    => 'Hallo',
			'ar'    => 'مرحبا',
		]);

		$html = $field->build();

		expect($html)->toContain('value="Hello"');
		expect($html)->toContain('value="Hallo"');
		expect($html)->toContain('value="مرحبا"');
	});
});

describe('LocalizedtextareaField rendering', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '123';
		$this->form->method('getLocales')->willReturn([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
			['code' => 'ar',    'label' => 'العربية',      'dir' => 'rtl'],
		]);
		$this->form->method('getDefaultLocale')->willReturn('en_US');
	});

	test('renders textarea elements per locale (not input)', function (): void {
		$field = new LocalizedtextareaField($this->form, 'summary');

		$html = $field->build();

		expect($html)->toContain('<textarea');
		expect($html)->not->toContain('<input type="text"');
	});

	test('propagates dir="rtl" to the textarea on RTL locales', function (): void {
		$field = new LocalizedtextareaField($this->form, 'summary');

		$html = $field->build();

		expect($html)->toMatch('/<textarea[^>]+dir="rtl"|dir="rtl"[^>]+(?=[^<]*<\/textarea>)/');
	});
});

describe('LocalizedstyledtextField rendering', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '123';
		$this->form->method('getLocales')->willReturn([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
			['code' => 'ar',    'label' => 'العربية',      'dir' => 'rtl'],
		]);
		$this->form->method('getDefaultLocale')->willReturn('en_US');
	});

	test('wraps each per-locale textarea in styledtext-wrapper for Tiptap pickup', function (): void {
		$field = new LocalizedstyledtextField($this->form, 'body');

		$html = $field->build();

		// Two locales → two styledtext-wrapper divs.
		expect(substr_count($html, 'styledtext-wrapper'))->toBe(2);
	});

	test('propagates dir="rtl" to the textarea on RTL locales', function (): void {
		$field = new LocalizedstyledtextField($this->form, 'body');

		$html = $field->build();

		expect($html)->toMatch('/<textarea[^>]+dir="rtl"|dir="rtl"[^>]+(?=[^<]*<\/textarea>)/');
	});
});
