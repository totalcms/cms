<?php

use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * Test relationalOptions support for both collection and DataView sources.
 */
describe('Relational Options', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = '';
	});

	/**
	 * Helper to invoke buildRelationalOptions via reflection.
	 *
	 * @param array<string,mixed> $relationalSettings
	 *
	 * @return array<array<string,string>>
	 */
	function callBuildRelationalOptions(object $form, array $relationalSettings): array
	{
		$field = new FormField(
			form: $form,
			name: 'test',
			settings: ['relationalOptions' => $relationalSettings],
		);

		$method = new ReflectionMethod($field, 'buildRelationalOptions');

		return $method->invoke($field);
	}

	// --- Collection source (existing behavior) ---

	test('builds options from collection', function (): void {
		$this->form->method('propertiesForCollection')
			->with(['title', 'id'], 'blog', [])
			->willReturn([
				['id' => 'post-1', 'title' => 'First Post'],
				['id' => 'post-2', 'title' => 'Second Post'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'blog',
			'label'      => 'title',
			'value'      => 'id',
		]);

		expect($result)->toBe([
			['value' => 'post-1', 'label' => 'First Post'],
			['value' => 'post-2', 'label' => 'Second Post'],
		]);
	});

	test('builds options from collection with filters', function (): void {
		$this->form->method('propertiesForCollection')
			->with(
				['title', 'id'],
				'blog',
				['include' => 'published:true', 'exclude' => 'draft:true']
			)
			->willReturn([
				['id' => 'post-1', 'title' => 'Published Post'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'blog',
			'label'      => 'title',
			'value'      => 'id',
			'include'    => 'published:true',
			'exclude'    => 'draft:true',
		]);

		expect($result)->toHaveCount(1);
		expect($result[0])->toBe(['value' => 'post-1', 'label' => 'Published Post']);
	});

	// --- View source (new behavior) ---

	test('builds options from DataView', function (): void {
		$this->form->method('propertiesForView')
			->with(['name', 'id'], 'my-dataview', [])
			->willReturn([
				['id' => 'item-1', 'name' => 'Alpha'],
				['id' => 'item-2', 'name' => 'Beta'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'view'  => 'my-dataview',
			'label' => 'name',
			'value' => 'id',
		]);

		expect($result)->toBe([
			['value' => 'item-1', 'label' => 'Alpha'],
			['value' => 'item-2', 'label' => 'Beta'],
		]);
	});

	test('builds options from DataView with filters', function (): void {
		$this->form->method('propertiesForView')
			->with(
				['title', 'id'],
				'filtered-view',
				['include' => 'active:true']
			)
			->willReturn([
				['id' => 'v1', 'title' => 'Active Item'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'view'    => 'filtered-view',
			'label'   => 'title',
			'value'   => 'id',
			'include' => 'active:true',
		]);

		expect($result)->toHaveCount(1);
		expect($result[0])->toBe(['value' => 'v1', 'label' => 'Active Item']);
	});

	test('view takes precedence over collection when both specified', function (): void {
		$this->form->expects($this->never())->method('propertiesForCollection');
		$this->form->method('propertiesForView')
			->willReturn([
				['id' => 'v1', 'title' => 'From View'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'blog',
			'view'       => 'my-view',
			'label'      => 'title',
			'value'      => 'id',
		]);

		expect($result)->toHaveCount(1);
		expect($result[0]['label'])->toBe('From View');
	});

	// --- Edge cases ---

	test('returns empty when no collection or view specified', function (): void {
		$result = callBuildRelationalOptions($this->form, [
			'label' => 'title',
			'value' => 'id',
		]);

		expect($result)->toBe([]);
	});

	test('returns empty for non-array settings', function (): void {
		$field = new FormField(
			form: $this->form,
			name: 'test',
			settings: ['relationalOptions' => 'invalid'],
		);

		$method = new ReflectionMethod($field, 'buildRelationalOptions');
		$result = $method->invoke($field);

		expect($result)->toBe([]);
	});

	test('defaults label and value to id', function (): void {
		$this->form->method('propertiesForCollection')
			->with(['id'], 'items', [])
			->willReturn([
				['id' => 'a'],
				['id' => 'b'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'items',
		]);

		expect($result)->toBe([
			['value' => 'a', 'label' => 'a'],
			['value' => 'b', 'label' => 'b'],
		]);
	});

	test('supports multi-field labels with join', function (): void {
		$this->form->method('propertiesForView')
			->with(['firstName', 'lastName', 'id'], 'people-view', [])
			->willReturn([
				['id' => '1', 'firstName' => 'John', 'lastName' => 'Doe'],
				['id' => '2', 'firstName' => 'Jane', 'lastName' => 'Smith'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'view'  => 'people-view',
			'label' => 'firstName lastName',
			'value' => 'id',
			'join'  => ' ',
		]);

		expect($result)->toBe([
			['value' => '1', 'label' => 'John Doe'],
			['value' => '2', 'label' => 'Jane Smith'],
		]);
	});

	// --- Format template (new behavior) ---

	test('format template renders with literal text around placeholders', function (): void {
		$this->form->method('propertiesForCollection')
			->with(['title', 'route', 'id'], 'builder-pages', [])
			->willReturn([
				['id' => 'about', 'title' => 'About Us', 'route' => '/about'],
				['id' => 'home',  'title' => 'Home',     'route' => '/'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'builder-pages',
			'format'     => '${title} (${route})',
			'value'      => 'id',
		]);

		expect($result)->toBe([
			['value' => 'about', 'label' => 'About Us (/about)'],
			['value' => 'home',  'label' => 'Home (/)'],
		]);
	});

	test('format template fetches only properties referenced in placeholders', function (): void {
		// Only 'firstName', 'lastName', and the value 'id' should be requested.
		$this->form->expects($this->once())
			->method('propertiesForCollection')
			->with(['firstName', 'lastName', 'id'], 'authors', [])
			->willReturn([
				['id' => '1', 'firstName' => 'John', 'lastName' => 'Doe'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'authors',
			'format'     => '${firstName} ${lastName}',
			'value'      => 'id',
		]);

		expect($result)->toBe([
			['value' => '1', 'label' => 'John Doe'],
		]);
	});

	test('format template substitutes empty string for missing fields', function (): void {
		$this->form->method('propertiesForCollection')
			->willReturn([
				['id' => '1', 'title' => 'Has Title'], // no 'subtitle'
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'items',
			'format'     => '${title} - ${subtitle}',
			'value'      => 'id',
		]);

		expect($result[0]['label'])->toBe('Has Title - ');
	});

	test('format template wins over label when both are set', function (): void {
		$this->form->method('propertiesForCollection')
			->willReturn([
				['id' => '1', 'title' => 'T', 'name' => 'N'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'items',
			'label'      => 'name',
			'format'     => 'Page: ${title}',
			'value'      => 'id',
		]);

		expect($result[0]['label'])->toBe('Page: T');
	});

	test('format template supports the same placeholder used multiple times', function (): void {
		$this->form->method('propertiesForCollection')
			->with(['title', 'id'], 'items', [])
			->willReturn([
				['id' => '1', 'title' => 'Hello'],
			]);

		$result = callBuildRelationalOptions($this->form, [
			'collection' => 'items',
			'format'     => '${title} / ${title}',
			'value'      => 'id',
		]);

		expect($result[0]['label'])->toBe('Hello / Hello');
	});
});
