<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\DepotField;
use TotalCMS\Domain\Admin\FormField\FileField;
use TotalCMS\Domain\Admin\FormField\ImageField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * Regression test for the subfield-collision fix (commit 6344ca1f).
 *
 * Composite form fields (File, Image, Depot, Gallery) render inner sub-fields
 * with generic names like `name`, `tags`, `comments`, `password`. If one of
 * these is rendered via `$this->form->field()` instead of
 * `$this->form->subField()`, it will inherit settings/options from a top-level
 * schema property of the same name — which is the bug 6344ca1f fixed.
 *
 * These tests pin the composite fields to subField() by spying on both methods
 * and asserting that every inner-field call goes through subField().
 */
describe('Composite field subField regression', function (): void {
	beforeEach(function (): void {
		$this->fieldCalls    = [];
		$this->subFieldCalls = [];

		$self = $this;

		$this->form             = $this->createMock(TotalForm::class);
		$this->form->id         = '';
		$this->form->collection = 'test-collection';
		$this->form->api        = '/api';

		$this->form->method('isEditMode')->willReturn(false);

		$this->form->method('field')
			->willReturnCallback(function (string $name) use ($self): string {
				$self->fieldCalls[] = $name;

				return '';
			});

		$this->form->method('subField')
			->willReturnCallback(function (string $name) use ($self): string {
				$self->subFieldCalls[] = $name;

				return '';
			});
	});

	test('FileField renders every inner metadata field via subField()', function (): void {
		$field = new FileField(form: $this->form, name: 'myfile', value: []);
		$field->buildFormField();

		$expected = [
			'download', 'comments', 'tags',    // info section
			'protected', 'password',           // protection section
			'name', 'ext', 'size', 'count',
			'mime', 'uploadDate',              // meta section
		];

		foreach ($expected as $inner) {
			expect($this->subFieldCalls)->toContain($inner);
		}

		// If any of these names hit form->field(), the subfield-collision bug
		// would re-emerge. The spy catches that directly.
		expect($this->fieldCalls)->toBe([]);
	});

	test('ImageField renders every inner metadata field via subField()', function (): void {
		$field = new ImageField(form: $this->form, name: 'myimage', value: []);
		$field->buildFormField();

		// Subset of the most collision-prone names (generic words that
		// commonly appear as top-level properties on schemas).
		$expected = [
			'featured', 'alt', 'link', 'tags',
			'focalpoint-x', 'focalpoint-y',
			'exif-date', 'exif-title', 'exif-author',
			'height', 'width', 'size', 'name', 'mime', 'uploadDate',
		];

		foreach ($expected as $inner) {
			expect($this->subFieldCalls)->toContain($inner);
		}

		expect($this->fieldCalls)->toBe([]);
	});

	test('DepotField renders every inner metadata field via subField()', function (): void {
		$field = new DepotField(form: $this->form, name: 'mydepot', value: ['files' => []]);
		$field->buildFormField();

		$expected = [
			'protected', 'password',                    // protection dialog
			'addpath',                                  // add folder dialog
			'download', 'comments', 'tags',             // file info
			'name', 'ext', 'size', 'count',
			'mime', 'uploadDate',                       // file meta
		];

		foreach ($expected as $inner) {
			expect($this->subFieldCalls)->toContain($inner);
		}

		expect($this->fieldCalls)->toBe([]);
	});
});
