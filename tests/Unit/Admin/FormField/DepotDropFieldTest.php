<?php

use TotalCMS\Domain\Admin\FormField\DepotDropField;
use TotalCMS\Domain\Admin\TotalForm;

describe('DepotDropField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 'test-id';
	});

	test('DepotDropField → buildFormField returns HTML with input', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('<input');
		expect($html)->toContain('type="text"');
		expect($html)->toContain('name="files"');
	});

	test('DepotDropField → buildFormField includes dropzone', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('depot-drop-zone');
		expect($html)->toContain('dz-clickable');
	});

	test('DepotDropField → buildFormField includes overlay', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('dz-overlay');
	});

	test('DepotDropField → buildFormField includes preview list', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('total-preview');
		expect($html)->toContain('<ul');
	});

	test('DepotDropField → buildFormField includes upload button', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('depot-drop-upload');
		expect($html)->toContain('<button');
		expect($html)->toContain('Upload Files');
	});

	test('DepotDropField → buildFormField includes file template', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('<template');
		expect($html)->toContain('file-template');
	});

	test('DepotDropField → file template has correct structure', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('file-icon');
		expect($html)->toContain('filename');
		expect($html)->toContain('dz-preview');
		expect($html)->toContain('depot-drop-card');
	});

	test('DepotDropField → uses uuid for field id', function (): void {
		$field = new DepotDropField($this->form, 'my-files');

		$html = $field->buildFormField();

		expect($html)->toContain('id="field-');
	});

	test('DepotDropField → build returns full field structure', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->build();

		// The build() method should include the field wrapper
		expect($html)->toContain('form-group');
		expect($html)->toContain('data-type="depotDrop"');
	});
});
