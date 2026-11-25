<?php

/**
 * A gallery thumbnail without a name (an upload that failed or had not started)
 * sent its delete to `…/{property}/`. TrailingSlash dropped the slash, the URL
 * became the whole property, and every image in the gallery was deleted.
 */

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;
use TotalCMS\Action\Property\File\FileSaveAction;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\deleteJson;
use function TotalCMS\Slim\Pest\patchJson;
use function TotalCMS\Slim\Pest\postJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'shoot', 'type' => 'object', 'properties' => [
		'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'photos' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery'],
	], 'required' => ['id'], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'shoots', 'name' => 'Shoots', 'schema' => 'shoot']);

	$images = array_map(
		static fn (string $name): array => ['name' => $name, 'size' => 4, 'mime' => 'image/png'],
		['a.png', 'b.png', 'c.png'],
	);
	$c->get(ObjectSaver::class)->saveObject('shoots', ['id' => 'one', 'photos' => $images]);

	$this->dir = objectFilesPath('shoots', 'one') . '/photos';
	mkdir($this->dir, 0775, true);
	foreach ($images as $image) {
		file_put_contents("{$this->dir}/{$image['name']}", 'png!');
	}

	$this->names = static function (): array {
		$record = json_decode((string)file_get_contents(objectPath('shoots', 'one')), true);

		return array_column($record['photos'] ?? [], 'name');
	};
	$this->files = fn (): array => array_map(basename(...), glob("{$this->dir}/*.png") ?: []);
});

it('refuses a delete that ends in a slash', function (string $path): void {
	$response = deleteJson($path);

	expect($response->getStatusCode())->toBe(400)
		->and((string)$response->getBody())->toContain('slash')
		->and(($this->names)())->toBe(['a.png', 'b.png', 'c.png'])
		->and(($this->files)())->toBe(['a.png', 'b.png', 'c.png']);
})->with([
	'property'   => '/api/collections/shoots/one/photos/',
	'object'     => '/api/collections/shoots/one/',
	'collection' => '/api/collections/shoots/',
]);

// The admin's JavaScript sends every delete as a POST with this header.
it('refuses it as a method override too', function (): void {
	$response = postJson('/api/collections/shoots/one/photos/', [], ['X-Http-Method-Override' => 'DELETE']);

	expect($response->getStatusCode())->toBe(400)
		->and(($this->files)())->toBe(['a.png', 'b.png', 'c.png']);
});

it('refuses a name that sanitizes to nothing', function (): void {
	$response = deleteJson('/api/collections/shoots/one/photos/..');

	expect($response->getStatusCode())->toBe(404)
		->and(($this->names)())->toBe(['a.png', 'b.png', 'c.png'])
		->and(($this->files)())->toBe(['a.png', 'b.png', 'c.png']);
});

it('still deletes one image, and the property when it is named', function (): void {
	expect(deleteJson('/api/collections/shoots/one/photos/a.png')->getStatusCode())->toBe(200)
		->and(($this->names)())->toBe(['b.png', 'c.png'])
		->and(($this->files)())->toBe(['b.png', 'c.png']);

	expect(deleteJson('/api/collections/shoots/one/photos')->getStatusCode())->toBe(200)
		->and(($this->names)())->toBe([]);
});

it('drops gallery entries without a name on save', function (): void {
	$record   = json_decode((string)file_get_contents(objectPath('shoots', 'one')), true);
	[$a, $b]  = $record['photos'];
	$response = patchJson('/api/collections/shoots/one', ['photos' => [$a, [], $b, ['name' => '', 'alt' => 'x']]]);

	expect($response->getStatusCode())->toBe(200)
		->and(($this->names)())->toBe(['a.png', 'b.png']);

	$record = json_decode((string)file_get_contents(objectPath('shoots', 'one')), true);
	expect(array_is_list($record['photos']))->toBeTrue();
});

it('deletes an image whose name needs encoding in a URL', function (): void {
	$record = json_decode((string)file_get_contents(objectPath('shoots', 'one')), true);
	patchJson('/api/collections/shoots/one', ['photos' => [...$record['photos'], ['name' => 'd #1.png']]]);
	file_put_contents("{$this->dir}/d #1.png", 'png!');

	expect(deleteJson('/api/collections/shoots/one/photos/d%20%231.png')->getStatusCode())->toBe(200)
		->and(($this->names)())->toBe(['a.png', 'b.png', 'c.png'])
		->and(($this->files)())->toBe(['a.png', 'b.png', 'c.png']);
});

// The gallery field binds its thumbnail to this name.
it('answers an upload with the name the file was stored under', function (): void {
	$upload = function (): string {
		$path = sys_get_temp_dir() . '/gallery-' . uniqid() . '.jpg';
		copy(__DIR__ . '/../test-data/test-image.jpg', $path);
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/x')
			->withUploadedFiles(['photos' => new UploadedFile($path, 'Hochzeit Fürst 001.jpg', 'image/jpeg', (int)filesize($path), UPLOAD_ERR_OK)])
			->withParsedBody([]);

		$response = $this->app->getContainer()->get(FileSaveAction::class)($request, new Response(), ['collection' => 'shoots', 'id' => 'one', 'property' => 'photos']);

		return json_decode((string)$response->getBody(), true)['meta']['name'];
	};

	// The same file twice: cleaned, then changed because it is taken.
	$first  = $upload();
	$second = $upload();

	expect($first)->not->toContain(' ')
		->and($second)->not->toBe($first)
		->and(($this->names)())->toBe(['a.png', 'b.png', 'c.png', $first, $second])
		->and("{$this->dir}/{$first}")->toBeFile()
		->and("{$this->dir}/{$second}")->toBeFile();
});
