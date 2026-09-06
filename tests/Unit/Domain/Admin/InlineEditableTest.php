<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\InlineEditable;

describe('InlineEditable', function (): void {
	test('scalar and choice fields edit inline', function (): void {
		foreach (['text', 'textarea', 'number', 'range', 'price', 'toggle', 'checkbox', 'select', 'radio', 'multiselect', 'multicheckbox', 'checklist', 'date', 'datetime', 'time', 'url', 'email', 'phone', 'color', 'list', 'styledtext'] as $type) {
			expect(InlineEditable::supports($type))->toBeTrue($type);
		}
	});

	test('readonly and disabled properties do not, whatever their field', function (): void {
		expect(InlineEditable::allows(['field' => 'text']))->toBeTrue();
		expect(InlineEditable::allows(['field' => 'datetime', 'settings' => ['readonly' => true]]))->toBeFalse();
		expect(InlineEditable::allows(['field' => 'text', 'settings' => ['disabled' => true]]))->toBeFalse();
		expect(InlineEditable::allows(['field' => 'image']))->toBeFalse();
		expect(InlineEditable::allows([]))->toBeFalse();
	});

	test('identity, secrets and composites do not', function (): void {
		foreach (['id', 'slug', 'hidden', 'password', 'secret', 'image', 'gallery', 'file', 'depot', 'deck', 'deckTable', 'card', 'code', 'svg', 'json', 'localizedtext', 'localizedstyledtext', 'localizedtextarea', ''] as $type) {
			expect(InlineEditable::supports($type))->toBeFalse($type);
		}
	});
});
