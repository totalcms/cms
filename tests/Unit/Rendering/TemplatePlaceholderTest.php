<?php

use TotalCMS\Domain\Rendering\Utilities\TemplatePlaceholder;

describe('TemplatePlaceholder::extractKeys', function (): void {
	test('extracts a single key', function (): void {
		expect(TemplatePlaceholder::extractKeys('${title}'))->toBe(['title']);
	});

	test('extracts multiple distinct keys in order of first appearance', function (): void {
		expect(TemplatePlaceholder::extractKeys('${title} (${route})'))
			->toBe(['title', 'route']);
	});

	test('deduplicates repeated keys', function (): void {
		expect(TemplatePlaceholder::extractKeys('${title} / ${title}'))
			->toBe(['title']);
	});

	test('returns empty array when template has no placeholders', function (): void {
		expect(TemplatePlaceholder::extractKeys('plain text'))->toBe([]);
	});

	test('captures composite keys like oid-00000', function (): void {
		expect(TemplatePlaceholder::extractKeys('${title}-${oid-00000}'))
			->toBe(['title', 'oid-00000']);
	});

	test('captures dotted keys like deck.field', function (): void {
		expect(TemplatePlaceholder::extractKeys('${items.total}'))
			->toBe(['items.total']);
	});
});

describe('TemplatePlaceholder::render', function (): void {
	test('substitutes a single placeholder', function (): void {
		$result = TemplatePlaceholder::render('${title}', fn (string $k): string => 'Hello');
		expect($result)->toBe('Hello');
	});

	test('preserves literal text around placeholders', function (): void {
		$result = TemplatePlaceholder::render(
			'${title} (${route})',
			fn (string $k): string => match ($k) {
				'title' => 'About',
				'route' => '/about',
				default => '',
			},
		);
		expect($result)->toBe('About (/about)');
	});

	test('returns the template unchanged when there are no placeholders', function (): void {
		$result = TemplatePlaceholder::render('no placeholders here', fn (): string => 'X');
		expect($result)->toBe('no placeholders here');
	});

	test('casts non-string resolver returns to string', function (): void {
		$result = TemplatePlaceholder::render('${count}', fn (string $k): int => 42);
		expect($result)->toBe('42');
	});

	test('substitutes empty string when resolver returns empty', function (): void {
		$result = TemplatePlaceholder::render(
			'${a}-${b}',
			fn (string $k): string => $k === 'a' ? 'one' : '',
		);
		expect($result)->toBe('one-');
	});

	test('passes the raw key without ${} to the resolver', function (): void {
		$capturedKeys = [];
		TemplatePlaceholder::render('${title}-${oid-000}', function (string $k) use (&$capturedKeys): string {
			$capturedKeys[] = $k;

			return '';
		});
		expect($capturedKeys)->toBe(['title', 'oid-000']);
	});
});
