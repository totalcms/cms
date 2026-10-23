<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Form\Builder\CollectionForm;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/*
 * The registration fields (publicRegistration, requireEmailVerification,
 * registrationGroups) only mean something for a collection that holds users,
 * so CollectionForm hides them server-side unless the collection's schema is
 * `auth` or inherits from it. The new-collection form has no schema yet and
 * hides them too.
 *
 * The form renders out of the real container's FormServices; only the
 * collection record and the raw schemas the auth check reads are mocked.
 */
describe('CollectionForm registration fields', function (): void {
	beforeEach(function (): void {
		$this->real = bootstrap()->getContainer()->get(FormServices::class);
	});

	/**
	 * @param array<string,mixed>      $collection the collection record (null = the new-collection form)
	 * @param array<string,list<string>> $inherits  schema id => inheritFrom, for schemas the auth check reads
	 */
	$render = function (?array $collection, array $inherits = []): string {
		/** @var FormServices $real */
		$real = $this->real;

		// Partial: the real isOrInherits() reads the stubbed raw schemas; every
		// other read is stubbed as before.
		$schemaFetcher = $this->createPartialMock(SchemaFetcher::class, array_values(array_diff(get_class_methods(SchemaFetcher::class), ['isOrInherits', '__construct'])));
		// The forms' own schemas (collection, the record's schema) come from the real fetcher;
		// a schema the test names stands in for a custom one.
		$schemaFetcher->method('fetchSchema')->willReturnCallback(static function (string $id) use ($real, $inherits): SchemaData {
			if (array_key_exists($id, $inherits)) {
				return $real->schemaFetcher->fetchSchema('auth');
			}

			return $real->schemaFetcher->fetchSchema($id);
		});
		$schemaFetcher->method('fetchRawSchema')->willReturnCallback(static function (string $id) use ($real, $inherits): SchemaData {
			if (!array_key_exists($id, $inherits)) {
				return $real->schemaFetcher->fetchRawSchema($id);
			}
			$schema              = new SchemaData();
			$schema->id          = $id;
			$schema->inheritFrom = $inherits[$id];

			return $schema;
		});

		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		if ($collection !== null) {
			$data = new CollectionData();
			foreach ($collection as $key => $value) {
				$data->{$key} = $value;
			}
			$collectionFetcher->method('fetchCollection')->willReturn($data);
		}

		$args                      = get_object_vars($real);
		$args['schemaFetcher']     = $schemaFetcher;
		$args['collectionFetcher'] = $collectionFetcher;

		$form = new CollectionForm(new FormServices(...$args), new FormOptions(api: '/api', id: $collection['id'] ?? ''));

		return $form->autoBuild();
	};

	// The opening tag of the container whose grid area is $name.
	$container = static function (string $html, string $name): string {
		preg_match('/<div[^>]*--grid-area: ' . preg_quote($name, '/') . ';[^>]*>/', $html, $match);
		expect($match)->not->toBeEmpty("no container for {$name}");

		return $match[0];
	};

	$fields = ['publicRegistration', 'requireEmailVerification', 'registrationGroups'];

	it('hides all three on a collection that does not hold users', function () use ($render, $container, $fields): void {
		$html = $render->call($this, ['id' => 'blog', 'schema' => 'blog']);

		foreach ($fields as $name) {
			expect($container($html, $name))->toContain('field-hidden')->toContain('display: none;');
		}
	});

	it('shows the toggle on an auth collection', function () use ($render, $container): void {
		$html = $render->call($this, ['id' => 'members', 'schema' => 'auth']);

		expect($container($html, 'publicRegistration'))->not->toContain('field-hidden');
	});

	it('shows all three on an auth collection with registration on', function () use ($render, $container, $fields): void {
		$html = $render->call($this, ['id' => 'members', 'schema' => 'auth', 'publicRegistration' => true]);

		foreach ($fields as $name) {
			expect($container($html, $name))->not->toContain('field-hidden')->not->toContain('display: none;');
		}
	});

	it('counts a schema that inherits from auth as auth', function () use ($render, $container, $fields): void {
		$html = $render->call($this, ['id' => 'designers', 'schema' => 'designer'], ['designer' => ['auth']]);

		expect($container($html, 'publicRegistration'))->not->toContain('field-hidden');

		$on = $render->call($this, ['id' => 'designers', 'schema' => 'designer', 'publicRegistration' => true], ['designer' => ['auth']]);
		foreach ($fields as $name) {
			expect($container($on, $name))->not->toContain('field-hidden');
		}
	});

	it('does not count a schema that inherits from something else', function () use ($render, $container): void {
		$html = $render->call($this, ['id' => 'notes', 'schema' => 'note'], ['note' => ['blog']]);

		expect($container($html, 'publicRegistration'))->toContain('field-hidden');
	});

	it('hides all three on the new-collection form', function () use ($render, $container, $fields): void {
		$html = $render->call($this, null);

		foreach ($fields as $name) {
			expect($container($html, $name))->toContain('field-hidden')->toContain('display: none;');
		}
	});
});
