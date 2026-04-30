<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\DeckField;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Coverage for DeckField — the container that holds deck items, renders the
 * "add item" button and the new-item template. The test exercises init()
 * settings extraction, item construction from existing data, and the
 * buildFormField markup.
 */
describe('DeckField', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->metaResolver  = $this->createMock(PropertyMetaResolver::class);
		$this->metaResolver->method('resolvePreset')->willReturnArgument(0);
		$this->metaResolver->method('resolveTypePreset')->willReturn([]);

		// Minimal deck schema so DeckItem::buildSchemaBasedFields can succeed
		$this->deckSchema             = new SchemaData();
		$this->deckSchema->id         = 'comment';
		$this->deckSchema->required   = [];
		$this->deckSchema->properties = [
			'id'     => ['field' => 'id'],
			'author' => ['field' => 'text'],
		];
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->deckSchema);

		$this->form             = $this->createMock(TotalForm::class);
		$this->form->id         = 'post-1';
		$this->form->collection = 'blog';
		$this->form->api        = '/api';
		$this->form->method('isEditMode')->willReturn(false);
		$this->form->method('getSchemaFetcher')->willReturn($this->schemaFetcher);
		$this->form->method('getMetaResolver')->willReturn($this->metaResolver);
		$this->form->method('field')->willReturn('');
		$this->form->method('subField')->willReturn('');
	});

	test('init() extracts schemaref and deckItemLabel from settings', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			settings: [
				'schemaref'     => 'https://www.totalcms.co/schemas/deck/comment.json',
				'deckItemLabel' => '${author}: ${id}',
			],
		);

		$schemaref = (new ReflectionProperty($field, 'schemaref'))->getValue($field);
		$label     = (new ReflectionProperty($field, 'deckItemLabel'))->getValue($field);

		expect($schemaref)->toBe('https://www.totalcms.co/schemas/deck/comment.json');
		expect($label)->toBe('${author}: ${id}');
	});

	test('init() accepts the legacy `deckref` settings key as an alias', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			settings: [
				'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json',
			],
		);

		$schemaref = (new ReflectionProperty($field, 'schemaref'))->getValue($field);
		expect($schemaref)->toBe('https://www.totalcms.co/schemas/deck/comment.json');
	});

	test('init() defaults the deck item label to ${id} when not supplied', function (): void {
		$field = new DeckField(form: $this->form, name: 'comments');

		$label = (new ReflectionProperty($field, 'deckItemLabel'))->getValue($field);
		expect($label)->toBe('${id}');
	});

	test('init() builds a DeckItem for each entry in the value array', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			value   : [
				'c-1' => ['id' => 'c-1', 'author' => 'alice'],
				'c-2' => ['id' => 'c-2', 'author' => 'bob'],
			],
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);

		$items = (new ReflectionProperty($field, 'deckItems'))->getValue($field);
		expect($items)->toHaveCount(2);
		expect($items)->toHaveKey('c-1');
		expect($items)->toHaveKey('c-2');
	});

	test('init() silently skips non-array items in the value', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			value   : [
				'valid'   => ['id' => 'valid', 'author' => 'a'],
				'garbage' => 'not-an-array',
			],
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);

		$items = (new ReflectionProperty($field, 'deckItems'))->getValue($field);
		expect($items)->toHaveCount(1);
		expect($items)->toHaveKey('valid');
	});

	test('init() applies the cms-hide class when hide=true', function (): void {
		$field = new DeckField(
			form: $this->form,
			name: 'comments',
			hide: true,
		);

		$class = (new ReflectionProperty($field, 'class'))->getValue($field);
		expect($class)->toContain('cms-hide');
	});

	test('init() applies cms-hide when settings.hide is true', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			settings: ['hide' => true],
		);

		$class = (new ReflectionProperty($field, 'class'))->getValue($field);
		expect($class)->toContain('cms-hide');
	});

	test('buildFormField emits a hidden anchor input and the Add Item button', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);
		$html = $field->buildFormField();

		expect($html)->toContain('name="comments"');
		expect($html)->toContain('type="text"');
		expect($html)->toContain('Add Item');
	});

	test('buildFormField emits a deck-template when a deckref is set', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			settings: ['deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
		);
		$html = $field->buildFormField();

		expect($html)->toContain('<template');
		expect($html)->toContain('class="deck-template"');
	});

	test('buildFormField omits the template when no deckref is configured', function (): void {
		$field = new DeckField(form: $this->form, name: 'comments');
		$html  = $field->buildFormField();

		expect($html)->not->toContain('class="deck-template"');
		expect($html)->toContain('Add Item');
	});

	test('formFieldAttributes includes data-schemaref and data-deck-label-pattern', function (): void {
		$field = new DeckField(
			form    : $this->form,
			name    : 'comments',
			settings: [
				'schemaref'     => 'https://www.totalcms.co/schemas/deck/comment.json',
				'deckItemLabel' => '${author}',
			],
		);

		$method     = new ReflectionMethod($field, 'formFieldAttributes');
		$attributes = $method->invoke($field);

		expect($attributes['data-schemaref'])->toBe('https://www.totalcms.co/schemas/deck/comment.json');
		expect($attributes['data-deck-label-pattern'])->toBe('${author}');
	});

	test('formFieldAttributes omits data-schemaref when empty', function (): void {
		$field = new DeckField(form: $this->form, name: 'comments');

		$method     = new ReflectionMethod($field, 'formFieldAttributes');
		$attributes = $method->invoke($field);

		expect($attributes)->not->toHaveKey('data-schemaref');
	});
});
