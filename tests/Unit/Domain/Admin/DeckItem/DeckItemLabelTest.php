<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\DeckItem\DeckItem;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * A deck item's label is printed into the admin page as HTML. Not every field
 * is sanitized when it is saved (code, markdown, anything with
 * `htmlclean: false`), so the label sanitizes what it interpolates.
 */
describe('DeckItem label', function (): void {
	beforeEach(function (): void {
		$this->form = $this->createMock(TotalForm::class);
		$this->form->method('getSchemaFetcher')->willReturn($this->createMock(SchemaFetcher::class));
		$this->form->method('getMetaResolver')->willReturn($this->createMock(PropertyMetaResolver::class));
	});

	function deckLabel(TotalForm $form, array $data, string $pattern): string
	{
		$item   = new DeckItem($form, 'item-1', itemData: $data, deckItemLabel: $pattern);
		$method = (new ReflectionClass($item))->getMethod('generateLabel');

		return $method->invoke($item);
	}

	test('plain text is printed as it is', function (): void {
		expect(deckLabel($this->form, ['name' => 'Star Dust & Co'], 'Item: ${name}'))->toBe('Item: Star Dust & Co');
	});

	test('script and event handlers in a value are removed', function (): void {
		$label = deckLabel($this->form, ['body' => 'Hi <img src=x onerror="alert(1)"><script>alert(2)</script>'], '${body}');

		expect($label)->not->toContain('onerror')
			->and($label)->not->toContain('<script')
			->and($label)->toContain('Hi');
	});

	test('harmless formatting in a value is kept', function (): void {
		expect(deckLabel($this->form, ['body' => '<strong>Bold</strong> title'], '${body}'))->toContain('<strong>Bold</strong>');
	});

	test('an svg value keeps its shape and loses its script', function (): void {
		$svg   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4" onload="alert(1)"><circle cx="2" cy="2" r="2"/><script>alert(2)</script></svg>';
		$label = deckLabel($this->form, ['icon' => $svg], '${icon}');

		expect($label)->toContain('<circle')
			->and($label)->not->toContain('onload')
			->and($label)->not->toContain('<script');
	});

	test('the item id still fills an empty label', function (): void {
		expect(deckLabel($this->form, [], '${missing}'))->toBe('item-1');
	});
});
