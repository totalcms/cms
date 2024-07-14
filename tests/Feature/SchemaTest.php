<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
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
			'$id'     => "https://www.totalcms.co/schemas/{$id}.json",
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
		]);

	$this->assertFileExists(schemaPath($id));
});

it('cannot save a reserved schema', function (): void {
	$reservedSchemas = glob(__DIR__ . '/../../schemas/*.json');
	expect($reservedSchemas)->toBeArray()->not->toBeEmpty();
	foreach ($reservedSchemas as $schema) {
		$id = basename($schema, '.json');
		postJson('/schemas', ['id' => $id])
			->assertInternalServerError()
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
			'$id'     => "https://www.totalcms.co/schemas/{$id}.json",
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
