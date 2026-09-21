<?php

/**
 * Reproduction of a customer report against 3.5.2: deleting an image from a
 * saved object removes the file from disk BEFORE the record is re-saved with
 * the property nulled. When that re-save is rejected — here by a maxLength
 * added to a styledtext property after its content was written — the file is
 * gone and the record still points at it.
 */

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\deleteJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('keeps the image file when the record re-save is rejected', function (): void {
	$c      = $this->app->getContainer();
	$schema = static fn (?int $maxLength): array => ['id' => 'page-repro', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'body'  => array_filter(['type' => 'string', 'field' => 'styledtext', 'maxLength' => $maxLength]),
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
	], 'required' => ['id', 'title'], 'index' => ['id', 'title']];

	// 1. The content is written while the schema has no length limit.
	$c->get(SchemaSaver::class)->saveSchema($schema(null));
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'pages-repro', 'name' => 'Pages', 'schema' => 'page-repro']);
	$c->get(ObjectSaver::class)->saveObject('pages-repro', [
		'id'    => 'about',
		'title' => 'About',
		'body'  => str_repeat('<p>lorem ipsum</p>', 20),
		'photo' => ['name' => 'hero.png', 'size' => 4, 'mime' => 'image/png'],
	]);
	$dir = objectFilesPath('pages-repro', 'about') . '/photo';
	mkdir($dir, 0775, true);
	file_put_contents("$dir/hero.png", 'png!');

	// 2. A maxLength is added later, below the length of what is stored.
	$c->get(SchemaSaver::class)->saveSchema($schema(50));

	// 3. The trash icon on the image field: DELETE /collections/{c}/{id}/{property}.
	$response = deleteJson('/api/collections/pages-repro/about/photo');

	// The re-save is rejected — expected, and reported honestly.
	expect($response->getStatusCode())->toBe(400)
		->and((string)$response->getBody())->toContain('Schema Validation Failed');

	// The record still names the image, as before the click.
	$record = json_decode((string)file_get_contents(objectPath('pages-repro', 'about')), true);
	expect($record['photo']['name'] ?? null)->toBe('hero.png');

	// So the file it names must still exist. On 3.5.2 it does not.
	expect(file_exists("$dir/hero.png"))->toBeTrue('the image file was deleted although the record still points at it');
});
