<?php

/**
 * The gallery field's multi-delete: POST …/{property}/bulk/delete removes
 * several images with ONE save, so the object changes once — one
 * `object.updated` event, not one per image.
 */

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

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
		'title'  => ['type' => 'string', 'field' => 'text'],
	], 'required' => ['id'], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'shoots', 'name' => 'Shoots', 'schema' => 'shoot']);

	$images = array_map(
		static fn (string $name): array => ['name' => $name, 'size' => 4, 'mime' => 'image/png'],
		['a.png', 'b.png', 'c.png', 'd.png'],
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
	$this->files = function (): array {
		$files = array_map(basename(...), glob("{$this->dir}/*.png") ?: []);
		sort($files);

		return $files;
	};
	$this->url = '/api/collections/shoots/one/photos/bulk/delete';
});

it('removes several images with one save', function (): void {
	$updates = 0;
	$this->app->getContainer()->get(EventDispatcher::class)->listen(CoreEvent::OBJECT_UPDATED, static function () use (&$updates): void {
		$updates++;
	});

	$response = postJson($this->url, ['names' => ['a.png', 'c.png', 'd.png']]);
	$body     = json_decode((string)$response->getBody(), true);

	expect($response->getStatusCode())->toBe(200)
		->and($body['deleted'])->toBe(['a.png', 'c.png', 'd.png'])
		->and($body['missing'])->toBe([])
		->and(($this->names)())->toBe(['b.png'])
		->and(($this->files)())->toBe(['b.png'])
		->and($updates)->toBe(1);
});

it('reports names the gallery does not hold and deletes the rest', function (): void {
	$response = postJson($this->url, ['names' => ['b.png', 'gone.png', 'b.png']]);
	$body     = json_decode((string)$response->getBody(), true);

	expect($response->getStatusCode())->toBe(200)
		->and($body['deleted'])->toBe(['b.png'])
		->and($body['missing'])->toBe(['gone.png'])
		->and(($this->names)())->toBe(['a.png', 'c.png', 'd.png'])
		->and(($this->files)())->toBe(['a.png', 'c.png', 'd.png']);
});

it('saves nothing when no name matches', function (): void {
	$before = (string)file_get_contents(objectPath('shoots', 'one'));

	$body = json_decode((string)postJson($this->url, ['names' => ['x.png']])->getBody(), true);

	expect($body)->toBe(['deleted' => [], 'missing' => ['x.png']])
		->and((string)file_get_contents(objectPath('shoots', 'one')))->toBe($before);
});

// The disk delete uses the record's own name, never one from the request.
it('never reaches a file the gallery does not list', function (): void {
	file_put_contents(objectFilesPath('shoots', 'one') . '/secret.txt', 'keep');

	$body = json_decode((string)postJson($this->url, ['names' => ['../secret.txt', '..', '.', 'photos/a.png']])->getBody(), true);

	expect($body['deleted'])->toBe([])
		->and(objectFilesPath('shoots', 'one') . '/secret.txt')->toBeFile()
		->and(($this->files)())->toBe(['a.png', 'b.png', 'c.png', 'd.png']);
});

it('refuses a request without names', function (mixed $payload): void {
	$response = postJson($this->url, $payload);

	expect($response->getStatusCode())->toBe(400)
		->and(($this->names)())->toBe(['a.png', 'b.png', 'c.png', 'd.png']);
})->with([
	'no names'     => [[]],
	'not a list'   => [['names' => 'a.png']],
	'empty list'   => [['names' => []]],
	'blank names'  => [['names' => ['', '  ']]],
]);

it('refuses a property that is not a gallery', function (): void {
	$response = postJson('/api/collections/shoots/one/title/bulk/delete', ['names' => ['a.png']]);

	expect($response->getStatusCode())->toBe(400)
		->and(($this->names)())->toBe(['a.png', 'b.png', 'c.png', 'd.png']);
});

it('answers 404 for a property the schema does not have', function (): void {
	expect(postJson('/api/collections/shoots/one/nope/bulk/delete', ['names' => ['a.png']])->getStatusCode())->toBe(404);
});
