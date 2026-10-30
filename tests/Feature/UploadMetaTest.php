<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Domain\Object\Service\ObjectSaver;

// Meta sent with an upload (a photo dropped from a stock-photo panel):
// PropertyFileStore::describe() lays alt text and a credit over the file just
// stored, editable keys only, as the image dialog would.

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');

	$this->post = 'upload-meta-' . uniqid();
	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => $this->post, 'title' => 'Meta']);
	$this->store = $c->get(PropertyFileStore::class);

	// The saver moves the file it is given, so each upload gets its own copy.
	$this->upload = function (string $name): string {
		$path = sys_get_temp_dir() . '/' . uniqid() . '-' . $name;
		copy(__DIR__ . '/../test-data/test-image.jpg', $path);

		return $path;
	};
});

test('meta sent with an image upload sets alt and credit and ignores the rest', function (): void {
	$stored = $this->store->store('blog', $this->post, 'image', ($this->upload)('dish.jpg'));
	$stored = $this->store->describe($stored, 'blog', $this->post, 'image', [
		'alt'    => 'Burrata on grilled bread',
		'credit' => ['author' => 'Nadin', 'source' => 'Pixabay', 'sourceUrl' => 'https://pixabay.com/photos/1/'],
		'name'   => 'renamed.jpg',
	]);

	$image = $stored->object->toArray()['image'];
	expect($image['alt'])->toBe('Burrata on grilled bread')
		->and($image['credit'])->toBe(['author' => 'Nadin', 'source' => 'Pixabay', 'sourceUrl' => 'https://pixabay.com/photos/1/'])
		->and($image['name'])->toBe($stored->name)
		->and($image['name'])->not->toBe('renamed.jpg');
});

test('meta sent with a gallery upload lands on the new image only', function (): void {
	$first  = $this->store->store('blog', $this->post, 'gallery', ($this->upload)('one.jpg'));
	$second = $this->store->store('blog', $this->post, 'gallery', ($this->upload)('two.jpg'));
	$second = $this->store->describe($second, 'blog', $this->post, 'gallery', ['alt' => 'Second', 'credit' => ['source' => 'Pixabay']]);

	$gallery = array_column($second->object->toArray()['gallery'], null, 'name');
	expect($gallery[$second->name]['alt'])->toBe('Second')
		->and($gallery[$second->name]['credit'])->toBe(['source' => 'Pixabay'])
		->and($gallery[$first->name]['credit'] ?? [])->toBe([]);
});

test('a photo copied from a library gallery keeps its tags, link and focal point', function (): void {
	$meta = [
		'alt'        => 'Burrata with basil',
		'tags'       => ['starter', 'cheese'],
		'link'       => 'https://example.com/menu',
		'focalpoint' => ['x' => 30, 'y' => 60],
		'credit'     => ['author' => 'Nadin Sh', 'source' => 'Pexels'],
	];

	$image = $this->store->store('blog', $this->post, 'image', ($this->upload)('burrata.jpg'));
	$image = $this->store->describe($image, 'blog', $this->post, 'image', $meta)->object->toArray()['image'];

	$photo   = $this->store->store('blog', $this->post, 'gallery', ($this->upload)('burrata.jpg'));
	$photo   = $this->store->describe($photo, 'blog', $this->post, 'gallery', $meta);
	$gallery = array_column($photo->object->toArray()['gallery'], null, 'name')[$photo->name];

	foreach ([$image, $gallery] as $stored) {
		expect($stored)->toMatchArray($meta);
	}
});
