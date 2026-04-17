<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\DeckItem\DeckItem;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Covers DeckItem's preset inheritance pipeline. Deck items rendered inline
 * inside a parent object form go through DeckItem::buildFields, which must
 * resolve named presets and fall back to type-default presets — otherwise
 * sub-fields like styledtext ship with `{"preset":"st-simple"}` instead of
 * the actual preset values.
 */
describe('DeckItem preset resolution', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->metaResolver  = $this->createMock(PropertyMetaResolver::class);

		$this->form = $this->createMock(TotalForm::class);
		$this->form->method('getSchemaFetcher')->willReturn($this->schemaFetcher);
		$this->form->method('getMetaResolver')->willReturn($this->metaResolver);
	});

	function invokeResolve(DeckItem $deckItem, array $propertySchema): array
	{
		$ref    = new ReflectionClass($deckItem);
		$method = $ref->getMethod('resolveDeckFieldSettings');
		$method->setAccessible(true);

		return $method->invoke($deckItem, $propertySchema);
	}

	test('named preset expands via PropertyMetaResolver::resolvePreset', function (): void {
		$deckItem = new DeckItem($this->form, 'item-1');

		$this->metaResolver
			->expects($this->once())
			->method('resolvePreset')
			->with(['preset' => 'st-simple'])
			->willReturn(['height' => 300, 'buttons' => ['bold', 'italic']]);

		$this->metaResolver->expects($this->never())->method('resolveTypePreset');

		$result = invokeResolve($deckItem, [
			'field'    => 'styledtext',
			'settings' => ['preset' => 'st-simple'],
		]);

		expect($result)->toBe(['height' => 300, 'buttons' => ['bold', 'italic']]);
	});

	test('empty settings fall back to type-default preset', function (): void {
		$deckItem = new DeckItem($this->form, 'item-1');

		$this->metaResolver->method('resolvePreset')->willReturn([]);
		$this->metaResolver
			->expects($this->once())
			->method('resolveTypePreset')
			->with('styledtext')
			->willReturn(['height' => 400]);

		$result = invokeResolve($deckItem, ['field' => 'styledtext']);

		expect($result)->toBe(['height' => 400]);
	});

	test('no field type and no settings returns empty array', function (): void {
		$deckItem = new DeckItem($this->form, 'item-1');

		$this->metaResolver->method('resolvePreset')->willReturn([]);
		$this->metaResolver->expects($this->never())->method('resolveTypePreset');

		$result = invokeResolve($deckItem, ['label' => 'Orphan']);

		expect($result)->toBe([]);
	});

	test('explicit settings without a preset pass through unchanged', function (): void {
		$deckItem = new DeckItem($this->form, 'item-1');

		$this->metaResolver
			->method('resolvePreset')
			->with(['height' => 500, 'buttons' => ['bold']])
			->willReturn(['height' => 500, 'buttons' => ['bold']]);

		$this->metaResolver->expects($this->never())->method('resolveTypePreset');

		$result = invokeResolve($deckItem, [
			'field'    => 'styledtext',
			'settings' => ['height' => 500, 'buttons' => ['bold']],
		]);

		expect($result)->toBe(['height' => 500, 'buttons' => ['bold']]);
	});

	test('non-array settings are treated as empty', function (): void {
		$deckItem = new DeckItem($this->form, 'item-1');

		$this->metaResolver
			->method('resolvePreset')
			->with([])
			->willReturn([]);

		$this->metaResolver
			->expects($this->once())
			->method('resolveTypePreset')
			->with('text')
			->willReturn(['size' => 20]);

		$result = invokeResolve($deckItem, [
			'field'    => 'text',
			'settings' => 'not-an-array',
		]);

		expect($result)->toBe(['size' => 20]);
	});
});
