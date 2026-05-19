<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Data\LocalizedtextData;

describe('LocalizedtextData', function (): void {
	test('LocalizedtextData → creates with locale-keyed dict', function (): void {
		$data = new LocalizedtextData([
			'en_US' => 'About Us',
			'de'    => 'Über uns',
			'pt_BR' => 'Sobre nós',
		]);

		expect($data->values)->toBe([
			'en_US' => 'About Us',
			'de'    => 'Über uns',
			'pt_BR' => 'Sobre nós',
		]);
	});

	test('LocalizedtextData → creates empty when no input', function (): void {
		$data = new LocalizedtextData();

		expect($data->values)->toBe([]);
		expect((string)$data)->toBe('');
	});

	test('LocalizedtextData → transform returns the full dict', function (): void {
		$data = new LocalizedtextData([
			'en_US' => 'Hello',
			'de'    => 'Hallo',
		]);

		expect($data->transform())->toBe([
			'en_US' => 'Hello',
			'de'    => 'Hallo',
		]);
	});

	test('LocalizedtextData → __toString returns defaultLocale value when set', function (): void {
		$data = new LocalizedtextData(
			['en_US' => 'About', 'de' => 'Über'],
			['defaultLocale' => 'de']
		);

		expect((string)$data)->toBe('Über');
	});

	test('LocalizedtextData → __toString falls back to first value when no defaultLocale match', function (): void {
		$data = new LocalizedtextData(
			['en_US' => 'About', 'de' => 'Über'],
			['defaultLocale' => 'fr'] // not in dict
		);

		expect((string)$data)->toBe('About');
	});

	test('LocalizedtextData → __toString returns empty when dict is empty', function (): void {
		$data = new LocalizedtextData([], ['defaultLocale' => 'en_US']);

		expect((string)$data)->toBe('');
	});

	test('LocalizedtextData → accepts JSON string input (form post path)', function (): void {
		$data = new LocalizedtextData('{"en_US":"About","de":"Über"}');

		expect($data->values)->toBe([
			'en_US' => 'About',
			'de'    => 'Über',
		]);
	});

	test('LocalizedtextData → drops non-string locale keys', function (): void {
		/** @phpstan-ignore-next-line — deliberately invalid input */
		$data = new LocalizedtextData([
			'en_US' => 'Valid',
			0       => 'Numeric key dropped',
			''      => 'Empty key dropped',
		]);

		expect($data->values)->toBe(['en_US' => 'Valid']);
	});

	test('LocalizedtextData → coerces scalar values to strings', function (): void {
		$data = new LocalizedtextData([
			'en_US' => 'Text',
			'de'    => 42,    // number → string
			'pt_BR' => true,  // bool  → string
		]);

		expect($data->values['en_US'])->toBe('Text');
		expect($data->values['de'])->toBe('42');
		expect($data->values['pt_BR'])->toBe('1');
	});

	test('LocalizedtextData → handles RTL locales identically (no special handling needed)', function (): void {
		$data = new LocalizedtextData([
			'en_US' => 'Hello',
			'ar'    => 'مرحبا',
			'he'    => 'שלום',
		]);

		expect($data->values['ar'])->toBe('مرحبا');
		expect($data->values['he'])->toBe('שלום');
	});

	test('LocalizedtextData → handles unicode in values', function (): void {
		$data = new LocalizedtextData([
			'en_US' => 'Café 🌍 résumé',
			'ja'    => '世界',
		]);

		expect($data->values['en_US'])->toBe('Café 🌍 résumé');
		expect($data->values['ja'])->toBe('世界');
	});

	test('LocalizedtextData → preserves plain-text values without sanitization', function (): void {
		$data = new LocalizedtextData([
			'en_US' => 'Just a plain string',
			'de'    => 'Nur reiner Text',
		]);

		expect($data->values['en_US'])->toBe('Just a plain string');
		expect($data->values['de'])->toBe('Nur reiner Text');
	});

	test('LocalizedtextData → respects htmlclean=false to skip sanitization', function (): void {
		$html = '<p>Should stay <strong>intact</strong></p>';
		$data = new LocalizedtextData(
			['en_US' => $html],
			['htmlclean' => false]
		);

		expect($data->values['en_US'])->toBe($html);
	});
});
