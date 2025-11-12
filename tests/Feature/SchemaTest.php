<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

function schemaTestData(): array
{
	$json = file_get_contents(testData('new-schema.json'));

	return json_decode($json, true);
}

it('saves a new schema', function (): void {
	$schema = schemaTestData();
	$id     = $schema['id'];
	postJson('/schemas', $schema)
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id'      => $id,
			'$id'     => "https://www.totalcms.co/schemas/custom/{$id}.json",
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
		]);

	$this->assertFileExists(schemaPath($id));
});

it('cannot save a reserved schema', function (): void {
	$reservedSchemas = glob(reservedSchemaPath() . '*.json');
	expect($reservedSchemas)->toBeArray()->not->toBeEmpty();
	foreach ($reservedSchemas as $schema) {
		$json     = file_get_contents($schema);
		$response = postJson('/schemas', json_decode($json, true));

		// Debug output
		if ($response->getStatusCode() !== 400) {
			echo "\nStatus: " . $response->getStatusCode() . "\n";
			echo 'Body: ' . $response->getBody() . "\n";
			echo 'Schema: ' . basename($schema) . "\n";
		}

		$response->assertBadRequest()
			->assertSee('is reserved');
	}
});

it('fetches a schema', function (): void {
	$schema = schemaTestData();
	$id     = $schema['id'];
	get("/schemas/$id")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id'      => $id,
			'$id'     => "https://www.totalcms.co/schemas/custom/{$id}.json",
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
		]);
});

it('gets all available schemas', function (): void {
	get('/schemas')
		->assertOk()
		->assertJson();
});

it('gets all reserved schemas', function (): void {
	get('/schemas?filter=reserved')
		->assertOk()
		->assertJson();
});

it('gets all custom schemas', function (): void {
	get('/schemas?filter=custom')
		->assertOk()
		->assertJson();
});

it('can delete custom schemas', function (): void {
	$schema = schemaTestData();
	$id     = $schema['id'];

	delete("/schemas/$id")
		->assertOk();

	$this->assertFileDoesNotExist(schemaPath($id));
});

it('converts string boolean defaults to actual booleans when saving schema', function (): void {
	$schema = [
		'id'          => 'test-boolean-defaults',
		'description' => 'Test schema with boolean defaults',
		'properties'  => [
			'toggleTrue'  => [
				'field'   => 'toggle',
				'label'   => 'Toggle True',
				'default' => 'true', // String "true" should be converted to boolean true
			],
			'toggleFalse' => [
				'field'   => 'toggle',
				'label'   => 'Toggle False',
				'default' => 'false', // String "false" should be converted to boolean false
			],
			'checkboxTrue' => [
				'field'   => 'checkbox',
				'label'   => 'Checkbox True',
				'default' => 'TRUE', // Test case-insensitive conversion
			],
			'booleanType' => [
				'type'    => 'boolean',
				'field'   => 'toggle', // Field is required for all properties
				'label'   => 'Boolean Type',
				'default' => 'FALSE', // Test case-insensitive conversion
			],
			'numericOne' => [
				'field'   => 'toggle',
				'label'   => 'Numeric One',
				'default' => '1', // String "1" should be converted to boolean true
			],
			'numericZero' => [
				'field'   => 'toggle',
				'label'   => 'Numeric Zero',
				'default' => '0', // String "0" should be converted to boolean false
			],
		],
		'required'    => [],
		'index'       => [],
	];

	postJson('/schemas', $schema)->assertOk()->assertJson();

	// Read the saved schema file to verify boolean conversion
	$savedFile    = schemaPath($schema['id']);
	$savedContent = json_decode(file_get_contents($savedFile), true);

	// Verify that string "true" was converted to boolean true in the saved file
	expect($savedContent['properties']['toggleTrue']['default'])->toBe(true);
	expect($savedContent['properties']['toggleTrue']['default'])->toBeTrue();

	// Verify that string "false" was converted to boolean false in the saved file
	expect($savedContent['properties']['toggleFalse']['default'])->toBe(false);
	expect($savedContent['properties']['toggleFalse']['default'])->toBeFalse();

	// Verify checkbox field conversion
	expect($savedContent['properties']['checkboxTrue']['default'])->toBe(true);
	expect($savedContent['properties']['checkboxTrue']['default'])->toBeTrue();

	// Verify type-based boolean conversion (case-insensitive)
	expect($savedContent['properties']['booleanType']['default'])->toBe(false);
	expect($savedContent['properties']['booleanType']['default'])->toBeFalse();

	// Verify numeric "1" conversion
	expect($savedContent['properties']['numericOne']['default'])->toBe(true);
	expect($savedContent['properties']['numericOne']['default'])->toBeTrue();

	// Verify numeric "0" conversion
	expect($savedContent['properties']['numericZero']['default'])->toBe(false);
	expect($savedContent['properties']['numericZero']['default'])->toBeFalse();

	// Clean up
	delete("/schemas/{$schema['id']}")->assertOk();
});
