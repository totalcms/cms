<?php

use TotalCMS\Domain\Admin\FormField\FileField;
use TotalCMS\Domain\Admin\FormField\GalleryField;
use TotalCMS\Domain\Admin\FormField\ImageField;
use TotalCMS\Domain\Admin\FormField\ListField;
use TotalCMS\Domain\Admin\TotalForm;

describe('mediaTags propertyOptions source', function (): void {
	test('list field renders tags returned by mediaTagsForCollection', function (): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = 123;
		$form->method('mediaTagsForCollection')->willReturn(['sky', 'sea']);

		$field = new ListField($form, 'tags', settings: [
			'propertyOptions' => ['source' => 'mediaTags', 'field' => 'myimage', 'type' => 'image'],
		]);

		expect($field->build())
			->toContain('sky')
			->toContain('sea');
	});
});

describe('ImageField tag-suggestion wiring', function (): void {
	$invoke = function (object $field, array $imageData): array {
		$method = new ReflectionMethod($field, 'tagFieldSettings');
		return $method->invoke($field, $imageData);
	};

	// propertyOptions MUST live under `settings`: subField()/field() route only
	// declared FormField constructor params, and the field reads propertyOptions
	// from its `$settings`. A top-level key would be silently dropped (the
	// original regression: the tags field rendered no suggestions).
	test('attaches mediaTags propertyOptions under settings when the field is indexed', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(true);

		$field = new ImageField($form, 'myimage');

		expect($invoke($field, [])['settings']['propertyOptions'])
			->toBe(['source' => 'mediaTags', 'field' => 'myimage', 'type' => 'image']);
	});

	// Regression guard for the subField seam: feed tagFieldSettings' output
	// through a real ListField exactly as field() routes it ($settings['settings']
	// → the constructor `settings` param) and confirm the suggestions resolve.
	// If propertyOptions ever moves back to the top level, $settings['settings']
	// is empty and the options never render — this test fails.
	test('tagFieldSettings output resolves to rendered options through a list field', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(true);
		$form->method('mediaTagsForCollection')->willReturn(['sky', 'sea']);

		$settings = $invoke(new ImageField($form, 'myimage'), []);
		$list = new ListField($form, 'tags', settings: $settings['settings'] ?? []);

		expect($list->build())
			->toContain('sky')
			->toContain('sea');
	});

	test('omits the source when the field is not indexed', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(false);

		$field = new ImageField($form, 'myimage');

		expect($invoke($field, []))->not->toHaveKey('settings');
	});

	test('gallery fields use type gallery', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(true);

		$field = new GalleryField($form, 'mygallery');

		expect($invoke($field, [])['settings']['propertyOptions']['type'])->toBe('gallery');
	});

	test('nested (card/deck) image fields never source suggestions, even if indexed', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(true);

		// nestedPath set => field lives inside a card/deck; v1 scope is top-level only.
		$field = new ImageField($form, 'photo', nestedPath: 'mycard');

		expect($invoke($field, []))->not->toHaveKey('settings');
	});
});

describe('FileField tag-suggestion wiring', function (): void {
	$invoke = function (object $field, array $fileData): array {
		$method = new ReflectionMethod($field, 'tagFieldSettings');
		return $method->invoke($field, $fileData);
	};

	test('attaches mediaTags propertyOptions (type file) under settings when indexed', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(true);

		$field = new FileField($form, 'myfile');

		expect($invoke($field, [])['settings']['propertyOptions'])
			->toBe(['source' => 'mediaTags', 'field' => 'myfile', 'type' => 'file']);
	});

	test('omits the source when not indexed', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(false);

		expect($invoke(new FileField($form, 'myfile'), []))->not->toHaveKey('settings');
	});

	test('nested (card/deck) file fields never source suggestions, even if indexed', function () use ($invoke): void {
		$form = test()->createMock(TotalForm::class);
		$form->id = '';
		$form->method('isPropertyIndexed')->willReturn(true);

		$field = new FileField($form, 'doc', nestedPath: 'mycard');

		expect($invoke($field, []))->not->toHaveKey('settings');
	});
});
