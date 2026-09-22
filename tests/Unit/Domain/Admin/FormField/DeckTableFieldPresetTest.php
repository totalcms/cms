<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\DeckTableField;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Pin DeckTableField to the same preset-resolution behavior as DeckItem
 * and DeckItemForm. Without this, a deck-table cell whose schema says
 * `"settings": {"preset": "st-simple"}` would render with the raw `preset`
 * key in data-settings instead of the resolved preset values.
 */
describe('DeckTableField preset resolution', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->metaResolver  = $this->createMock(PropertyMetaResolver::class);

		$this->form             = $this->createMock(TotalForm::class);
		$this->form->id         = 'post-1';
		$this->form->collection = 'blog';
		$this->form->api        = '/api';
		$this->form->method('isEditMode')->willReturn(false);
		$this->form->method('getSchemaFetcher')->willReturn($this->schemaFetcher);
		$this->form->method('getMetaResolver')->willReturn($this->metaResolver);

		// Deck schema — what fetchSchema() returns for the deckref
		$this->deckSchema             = new SchemaData();
		$this->deckSchema->id         = 'comment';
		$this->deckSchema->required   = [];
		$this->deckSchema->properties = [
			'comment' => [
				'field'    => 'styledtext',
				'settings' => ['preset' => 'st-simple'],
			],
			'title' => [
				'field' => 'text',
			],
			'photo' => [
				'field' => 'image',
			],
		];

		$this->schemaFetcher->method('fetchSchema')->willReturn($this->deckSchema);
	});

	function recordingForm(object $ctx): TotalForm
	{
		$ctx->fieldCalls = [];
		$self            = $ctx;
		$ctx->form->method('field')
			->willReturnCallback(function (string $name, array $options = []) use ($self): string {
				$self->fieldCalls[] = ['name' => $name, 'options' => $options];

				return '';
			});

		return $ctx->form;
	}

	function buildDeckTableRow(DeckTableField $field): string
	{
		$ref    = new ReflectionClass($field);
		$method = $ref->getMethod('buildRow');

		return $method->invoke($field, 'item-1', []);
	}

	test('resolves named preset before rendering each cell', function (): void {
		$this->metaResolver
			->expects($this->atLeastOnce())
			->method('resolvePreset')
			->willReturnCallback(function (array $settings): array {
				if (isset($settings['preset']) && $settings['preset'] === 'st-simple') {
					return ['height' => 300, 'buttons' => ['bold', 'italic']];
				}

				return $settings;
			});

		recordingForm($this);

		$field = new DeckTableField(
			form    : $this->form,
			name    : 'comments',
			value   : [],
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);

		buildDeckTableRow($field);

		$commentCall = null;
		foreach ($this->fieldCalls as $call) {
			if ($call['name'] === 'comment') {
				$commentCall = $call;
				break;
			}
		}

		expect($commentCall)->not->toBeNull();
		// Raw `preset` key must NOT leak through; resolved values must be
		// in the settings options passed into form->field().
		expect($commentCall['options']['settings'])->not->toHaveKey('preset');
		expect($commentCall['options']['settings'])->toBe([
			'height'  => 300,
			'buttons' => ['bold', 'italic'],
		]);
	});

	test('falls back to type-default preset when a cell has no settings', function (): void {
		$this->metaResolver->method('resolvePreset')->willReturn([]);
		// Return a preset only for 'text'; other field types get empty array.
		// This lets the loop iterate both cells without mock-argument failures.
		$this->metaResolver
			->method('resolveTypePreset')
			->willReturnCallback(fn (string $field): array => $field === 'text' ? ['maxlength' => 120] : []);

		recordingForm($this);

		$field = new DeckTableField(
			form    : $this->form,
			name    : 'comments',
			value   : [],
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);

		buildDeckTableRow($field);

		$titleCall = null;
		foreach ($this->fieldCalls as $call) {
			if ($call['name'] === 'title') {
				$titleCall = $call;
				break;
			}
		}

		expect($titleCall)->not->toBeNull();
		expect($titleCall['options']['settings'])->toBe(['maxlength' => 120]);
	});

	test('cells get deck_context so ObjectForm skips parent-schema lookup', function (): void {
		$this->metaResolver->method('resolvePreset')->willReturn([]);
		$this->metaResolver->method('resolveTypePreset')->willReturn([]);

		recordingForm($this);

		$field = new DeckTableField(
			form    : $this->form,
			name    : 'comments',
			value   : [],
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);

		buildDeckTableRow($field);

		expect($this->fieldCalls)->not->toBeEmpty();
		foreach ($this->fieldCalls as $call) {
			expect($call['options']['deck_context'])->toBeTrue();
		}
	});
});

/**
 * An image or file cell renders its existing preview from a dotted path into
 * the item's folder (`comments.item-1`), exactly as a deck item's dialog does.
 * Without it the thumbnail of a saved row points at the top-level property
 * and shows broken. The template row has no id yet, so it gets none.
 */
describe('DeckTableField nested path for upload cells', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->metaResolver  = $this->createMock(PropertyMetaResolver::class);
		$this->metaResolver->method('resolvePreset')->willReturn([]);
		$this->metaResolver->method('resolveTypePreset')->willReturn([]);

		$this->form             = $this->createMock(TotalForm::class);
		$this->form->id         = 'post-1';
		$this->form->collection = 'blog';
		$this->form->api        = '/api';
		$this->form->method('getSchemaFetcher')->willReturn($this->schemaFetcher);
		$this->form->method('getMetaResolver')->willReturn($this->metaResolver);

		$schema             = new SchemaData();
		$schema->id         = 'slide';
		$schema->required   = [];
		$schema->properties = [
			'id'    => ['field' => 'id'],
			'photo' => ['field' => 'image'],
			'brief' => ['field' => 'file'],
			'title' => ['field' => 'text'],
		];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
	});

	function rowCalls(object $ctx, string $itemId): array
	{
		recordingForm($ctx);
		$field = new DeckTableField(form: $ctx->form, name: 'slides', value: [], settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/slide.json']);
		(new ReflectionClass($field))->getMethod('buildRow')->invoke($field, $itemId, []);
		$byName = [];
		foreach ($ctx->fieldCalls as $call) {
			$byName[$call['name']] = $call['options'];
		}

		return $byName;
	}

	test('a saved row hands every cell the dotted path into its item folder', function (): void {
		$calls = rowCalls($this, 'item-1');

		expect($calls['photo']['nestedPath'] ?? null)->toBe('slides.item-1')
			->and($calls['brief']['nestedPath'] ?? null)->toBe('slides.item-1')
			->and($calls['title']['nestedPath'] ?? null)->toBe('slides.item-1');
	});

	test('the template row has no id and therefore no nested path', function (): void {
		$calls = rowCalls($this, '');

		expect($calls['photo'])->not->toHaveKey('nestedPath');
	});
});

