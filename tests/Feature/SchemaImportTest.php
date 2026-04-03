<?php

namespace Tests\Feature;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

function validSchemaData(): array
{
	return [
		'id'          => 'imported-test-schema',
		'description' => 'A test schema for import testing',
		'properties'  => [
			'title' => [
				'type'    => 'string',
				'label'   => 'Title',
				'field'   => 'input',
				'default' => '',
			],
			'content' => [
				'type'    => 'string',
				'label'   => 'Content',
				'field'   => 'textarea',
				'default' => '',
			],
			'published' => [
				'type'    => 'boolean',
				'label'   => 'Published',
				'field'   => 'checkbox',
				'default' => false,
			],
		],
		'required' => ['title'],
		'index'    => ['title', 'published'],
	];
}

it('tests schema saving service directly', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$schemaData = validSchemaData();

	try {
		$savedSchema = $schemaSaver->saveSchema($schemaData);

		// Verify the schema was saved correctly
		expect($savedSchema->id)->toBe('imported-test-schema');
		expect($savedSchema->description)->toBe('A test schema for import testing');

		// Verify the file was created
		$this->assertFileExists(schemaPath('imported-test-schema'));

		// Verify the file contents
		$fileContent = file_get_contents(schemaPath('imported-test-schema'));
		$savedData   = json_decode($fileContent, true);

		expect($savedData)->toHaveKey('$id');
		expect($savedData)->toHaveKey('$schema');
		expect($savedData['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema');
		expect($savedData['id'])->toBe('imported-test-schema');
	} finally {
		// Clean up
		if (file_exists(schemaPath('imported-test-schema'))) {
			unlink(schemaPath('imported-test-schema'));
		}
	}
});

it('handles schema with missing required fields', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$invalidSchema = [
		'description' => 'Missing required id field',
		'properties'  => [], // SchemaSaver expects properties key
	];

	// This should fail during validation because 'id' is missing
	expect(fn () => $schemaSaver->saveSchema($invalidSchema))
		->toThrow(\UnexpectedValueException::class);
});

it('handles schema with invalid id format', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$invalidSchema       = validSchemaData();
	$invalidSchema['id'] = 'Invalid ID With Spaces!@#';

	expect(fn () => $schemaSaver->saveSchema($invalidSchema))
		->toThrow(\Exception::class);
});

it('handles reserved schema id conflicts', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$reservedSchema       = validSchemaData();
	$reservedSchema['id'] = 'blog'; // This should be a reserved schema

	expect(fn () => $schemaSaver->saveSchema($reservedSchema))
		->toThrow(\Exception::class);
});

it('handles duplicate schema imports gracefully', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$schemaData       = validSchemaData();
	$schemaData['id'] = 'duplicate-test';

	try {
		// First save should succeed
		$firstSave = $schemaSaver->saveSchema($schemaData);
		expect($firstSave->id)->toBe('duplicate-test');

		// Second save should also succeed (overwrite existing)
		$secondSave = $schemaSaver->saveSchema($schemaData);
		expect($secondSave->id)->toBe('duplicate-test');
	} finally {
		if (file_exists(schemaPath('duplicate-test'))) {
			unlink(schemaPath('duplicate-test'));
		}
	}
});

it('handles schema with complex nested properties', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$complexSchema = [
		'id'          => 'complex-schema-test',
		'description' => 'Complex nested schema',
		'properties'  => [
			'metadata' => [
				'type'       => 'object',
				'label'      => 'Metadata',
				'field'      => 'object',
				'properties' => [
					'author' => [
						'type'  => 'string',
						'label' => 'Author',
						'field' => 'input',
					],
					'tags' => [
						'type'  => 'array',
						'label' => 'Tags',
						'field' => 'list',
						'items' => [
							'type' => 'string',
						],
					],
				],
			],
			'settings' => [
				'type'       => 'object',
				'label'      => 'Settings',
				'field'      => 'object',
				'properties' => [
					'enabled' => [
						'type'    => 'boolean',
						'field'   => 'checkbox',
						'default' => true,
					],
					'priority' => [
						'type'    => 'number',
						'field'   => 'number',
						'minimum' => 1,
						'maximum' => 10,
					],
				],
			],
		],
		'required' => ['metadata'],
		'index'    => ['metadata'],
	];

	try {
		$savedSchema = $schemaSaver->saveSchema($complexSchema);
		expect($savedSchema->id)->toBe('complex-schema-test');
	} finally {
		if (file_exists(schemaPath('complex-schema-test'))) {
			unlink(schemaPath('complex-schema-test'));
		}
	}
});

it('handles schema with $ref properties', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$schemaWithRefs = [
		'id'          => 'ref-schema-test',
		'description' => 'Schema with $ref properties',
		'properties'  => [
			'image' => [
				'$ref'  => 'https://www.totalcms.co/schemas/properties/image.json',
				'label' => 'Featured Image',
				'field' => 'image',
			],
			'gallery' => [
				'$ref'  => 'https://www.totalcms.co/schemas/properties/gallery.json',
				'label' => 'Image Gallery',
				'field' => 'gallery',
			],
			'date' => [
				'$ref'  => 'https://www.totalcms.co/schemas/properties/date.json',
				'label' => 'Publication Date',
				'field' => 'date',
			],
		],
		'required' => ['date'],
		'index'    => ['date'],
	];

	try {
		$savedSchema = $schemaSaver->saveSchema($schemaWithRefs);
		expect($savedSchema->id)->toBe('ref-schema-test');
	} finally {
		if (file_exists(schemaPath('ref-schema-test'))) {
			unlink(schemaPath('ref-schema-test'));
		}
	}
});

it('handles various field types correctly', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$fieldTypesSchema = [
		'id'          => 'field-types-test',
		'description' => 'Schema with various field types',
		'properties'  => [
			'text_input' => [
				'type'  => 'string',
				'label' => 'Text Input',
				'field' => 'input',
			],
			'textarea' => [
				'type'  => 'string',
				'label' => 'Textarea',
				'field' => 'textarea',
			],
			'number' => [
				'type'  => 'number',
				'label' => 'Number',
				'field' => 'number',
			],
			'checkbox' => [
				'type'  => 'boolean',
				'label' => 'Checkbox',
				'field' => 'checkbox',
			],
			'select' => [
				'type'    => 'string',
				'label'   => 'Select',
				'field'   => 'select',
				'options' => ['option1', 'option2', 'option3'],
			],
			'list' => [
				'type'  => 'array',
				'label' => 'List',
				'field' => 'list',
			],
		],
		'required' => ['text_input'],
		'index'    => ['text_input', 'number', 'checkbox'],
	];

	try {
		$savedSchema = $schemaSaver->saveSchema($fieldTypesSchema);
		expect($savedSchema->id)->toBe('field-types-test');
	} finally {
		if (file_exists(schemaPath('field-types-test'))) {
			unlink(schemaPath('field-types-test'));
		}
	}
});

it('validates that saved schema contains proper JSON schema structure', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$schemaData = validSchemaData();

	try {
		$savedSchema = $schemaSaver->saveSchema($schemaData);

		// Get the array representation
		$schemaArray = $savedSchema->toArray();

		// Verify it contains required JSON Schema fields
		expect($schemaArray)->toHaveKey('$id');
		expect($schemaArray)->toHaveKey('$schema');
		expect($schemaArray['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema');
		expect($schemaArray['$id'])->toContain('schemas/custom/');
	} finally {
		if (file_exists(schemaPath('imported-test-schema'))) {
			unlink(schemaPath('imported-test-schema'));
		}
	}
});

// Test schema import via regular POST with JSON (simulating what might happen with form submissions)
it('can save schema via direct service call with malformed data', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	// Test with null data
	expect(fn () => $schemaSaver->saveSchema(null))
		->toThrow(\TypeError::class);

	// Test with empty array
	expect(fn () => $schemaSaver->saveSchema([]))
		->toThrow(\InvalidArgumentException::class);

	// Test with invalid structure (missing properties key)
	expect(fn () => $schemaSaver->saveSchema(['invalid' => 'data']))
		->toThrow(\InvalidArgumentException::class);
});

it('validates schema properties correctly', function (): void {
	$app         = bootstrap();
	$container   = $app->getContainer();
	$schemaSaver = $container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);

	$schemaWithInvalidProperties = [
		'id'          => 'invalid-props-test',
		'description' => 'Schema with invalid properties',
		'properties'  => 'not-an-object', // Should be an object
		'required'    => ['title'],
		'index'       => ['title'],
	];

	expect(fn () => $schemaSaver->saveSchema($schemaWithInvalidProperties))
		->toThrow(\InvalidArgumentException::class);
});
