<?php

declare(strict_types=1);

use TotalCMS\Domain\Backup\Service\SnapshotComparison;

// What changed between two states of a record, field by field, in a shape a
// page can print. It never returns the value of a secret field, and for every
// file it says where under the property the file sits, so the caller can ask
// whether that file can still be brought back.

describe('SnapshotComparison', function (): void {
	beforeEach(function (): void {
		$member = [
			'id'    => ['field' => 'id', 'type' => 'string'],
			'name'  => ['field' => 'text', 'type' => 'string', 'label' => 'Name'],
			'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'label' => 'Photo'],
			'reel'  => ['type' => 'video', 'field' => 'video', 'label' => 'Reel'],
		];

		$this->properties = [
			'id'        => ['field' => 'id', 'type' => 'string'],
			'title'     => ['field' => 'text', 'type' => 'string', 'label' => 'Title'],
			'price'     => ['field' => 'number', 'type' => 'number', 'label' => 'Price'],
			'featured'  => ['field' => 'toggle', 'type' => 'boolean', 'label' => 'Featured'],
			'tags'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/list.json', 'field' => 'list', 'label' => 'Tags'],
			'content'   => ['field' => 'styledtext', 'type' => 'string', 'label' => 'Content'],
			'updated'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/date.json', 'field' => 'date', 'label' => 'Updated', 'settings' => ['onUpdate' => true]],
			'password'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/password.json', 'field' => 'password', 'label' => 'Password'],
			'apikey'    => ['field' => 'text', 'type' => 'string', 'label' => 'API Key', 'serverOnly' => true],
			'cover'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'label' => 'Cover'],
			'pdf'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
			'photos'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery', 'label' => 'Photos'],
			'downloads' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json', 'field' => 'depot', 'label' => 'Downloads'],
			'lead'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'label' => 'Lead', 'schemaref' => 'member'],
			'team'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'label' => 'Team', 'schemaref' => 'member'],
		];

		$this->compare = fn (array $before, array $after): array => (new SnapshotComparison(
			fn (array $definition): array => ($definition['schemaref'] ?? '') === 'member' ? $member : [],
		))->compare($this->properties, $before, $after);

		/** The changes keyed by label, each reduced to the keys named. */
		$this->pick = function (array $changes, string ...$keys): array {
			$picked = [];
			foreach ($changes as $change) {
				foreach ($keys as $key) {
					$picked[$change['label']][$key] = $change[$key] ?? null;
				}
			}

			return $picked;
		};

		$this->image = fn (string $name, int $size = 100, string $alt = ''): array => ['name' => $name, 'mime' => 'image/jpeg', 'size' => $size, 'alt' => $alt];
	});

	test('two identical records have no changes', function (): void {
		$record = ['id' => 'a', 'title' => 'Hello', 'cover' => ($this->image)('hero.jpg')];

		expect(($this->compare)($record, $record))->toBe([]);
	});

	test('plain values show before and after, in the schema\'s order, under the schema\'s labels', function (): void {
		$changes = ($this->compare)(
			['id' => 'a', 'featured' => false, 'price' => 49, 'title' => 'Autumn Sale', 'tags' => ['sale', 'autumn']],
			['id' => 'a', 'featured' => true, 'price' => 39, 'title' => 'Winter Sale', 'tags' => ['sale']],
		);

		expect(($this->pick)($changes, 'kind', 'before', 'after'))->toBe([
			'Title'    => ['kind' => 'text', 'before' => 'Autumn Sale', 'after' => 'Winter Sale'],
			'Price'    => ['kind' => 'text', 'before' => '49', 'after' => '39'],
			'Featured' => ['kind' => 'text', 'before' => 'No', 'after' => 'Yes'],
			'Tags'     => ['kind' => 'text', 'before' => 'sale, autumn', 'after' => 'sale'],
		]);
	});

	test('a field with no label is called by its name, and one the schema no longer has is still shown', function (): void {
		$changes = ($this->compare)(['pdf' => ['name' => 'a.pdf', 'mime' => 'application/pdf'], 'legacy' => 'old'], ['legacy' => 'new']);

		expect(array_column($changes, 'label'))->toBe(['pdf', 'legacy']);
	});

	test('a long value, or one with line breaks, is marked long, with both versions in full', function (): void {
		$changes = ($this->compare)(
			['content' => '<p>One</p>', 'title' => str_repeat('a', 121), 'legacy' => 'one line'],
			['content' => '<p>Two</p>', 'title' => 'short now', 'legacy' => "two\nlines"],
		);

		expect(($this->pick)($changes, 'kind', 'before'))->toBe([
			'Title'   => ['kind' => 'long', 'before' => str_repeat('a', 121)],
			// Short rich text reads fine on one line.
			'Content' => ['kind' => 'text', 'before' => '<p>One</p>'],
			'legacy'  => ['kind' => 'long', 'before' => 'one line'],
		]);
	});

	test('a structured value is laid out over lines, which makes it long', function (): void {
		$changes = ($this->compare)(['legacy' => ['key' => 'a', 'size' => 1]], ['legacy' => ['key' => 'b', 'size' => 2]]);

		expect($changes[0])->toMatchArray(['kind' => 'long', 'before' => "{\n    \"key\": \"a\",\n    \"size\": 1\n}"]);
	});

	test('a secret field says only that it changed', function (): void {
		$changes = ($this->compare)(['password' => '$2y$10$oldhash', 'apikey' => 'sk-old'], ['password' => '$2y$10$newhash', 'apikey' => 'sk-new']);

		expect(($this->pick)($changes, 'kind', 'before', 'after', 'password'))->toBe([
			'Password' => ['kind' => 'secret', 'before' => '', 'after' => '', 'password' => true],
			'API Key'  => ['kind' => 'secret', 'before' => '', 'after' => '', 'password' => false],
		])
			->and(json_encode($changes))->not->toContain('hash')->not->toContain('sk-');
	});

	test('a date that stamps itself on every save is not a change worth showing', function (): void {
		expect(($this->compare)(['updated' => '2026-11-20T10:00:00'], ['updated' => '2026-11-21T10:00:00']))->toBe([]);
	});

	test('an image or a file shows the file name, its size, and where it sits', function (): void {
		$changes = ($this->compare)(
			['cover' => ($this->image)('hero.jpg', 412), 'pdf' => ['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 9]],
			['cover' => ($this->image)('banner.jpg', 300), 'pdf' => ['name' => '', 'mime' => '']],
		);

		expect(($this->pick)($changes, 'kind', 'property', 'path', 'before', 'beforeSize', 'after', 'afterSize'))->toBe([
			'Cover' => ['kind' => 'file', 'property' => 'cover', 'path' => '', 'before' => 'hero.jpg', 'beforeSize' => 412, 'after' => 'banner.jpg', 'afterSize' => 300],
			'pdf'   => ['kind' => 'file', 'property' => 'pdf', 'path' => '', 'before' => 'a.pdf', 'beforeSize' => 9, 'after' => '', 'afterSize' => null],
		]);
	});

	test('a file replaced by one of the same name is a change: the size tells them apart', function (): void {
		$changes = ($this->compare)(['cover' => ($this->image)('hero.jpg', 412)], ['cover' => ($this->image)('hero.jpg', 500)]);

		expect($changes)->toHaveCount(1)
			->and($changes[0])->toMatchArray(['kind' => 'file', 'before' => 'hero.jpg', 'beforeSize' => 412, 'after' => 'hero.jpg', 'afterSize' => 500]);
	});

	test('the same file with other details (alt text, focal point) is a note, not a file change', function (): void {
		$changes = ($this->compare)(['cover' => ($this->image)('hero.jpg', 412, 'Old alt')], ['cover' => ($this->image)('hero.jpg', 412, 'New alt')]);

		expect(($this->pick)($changes, 'kind', 'before', 'after'))->toBe(['Cover' => ['kind' => 'text', 'before' => 'hero.jpg', 'after' => 'hero.jpg (details changed)']]);
	});

	test('a gallery shows the pictures added and removed', function (): void {
		$changes = ($this->compare)(
			['photos' => [($this->image)('a.jpg', 1), ($this->image)('b.jpg', 2)]],
			['photos' => [($this->image)('b.jpg', 2), ($this->image)('c.jpg', 3)]],
		);

		expect($changes)->toHaveCount(1)
			->and($changes[0])->toMatchArray([
				'kind'     => 'files',
				'label'    => 'Photos',
				'property' => 'photos',
				'removed'  => [['path' => '', 'name' => 'a.jpg', 'size' => 1]],
				'added'    => [['path' => '', 'name' => 'c.jpg', 'size' => 3]],
			]);
	});

	test('a gallery with the same pictures in another order, or with edited captions, says so', function (): void {
		$changes = ($this->compare)(
			['photos' => [($this->image)('a.jpg'), ($this->image)('b.jpg')]],
			['photos' => [($this->image)('b.jpg'), ($this->image)('a.jpg')]],
		);

		expect($changes[0])->toMatchArray(['kind' => 'files', 'added' => [], 'removed' => []]);
	});

	test('a depot shows the files added and removed, each with its folder', function (): void {
		$file   = fn (string $name, int $size = 5): array => ['name' => $name, 'mime' => 'application/pdf', 'size' => $size];
		$folder = fn (string $name, array $files): array => ['name' => $name, 'mime' => 'folder', 'files' => $files];

		$changes = ($this->compare)(
			['downloads' => ['protected' => true, 'files' => [$file('readme.txt'), $folder('Price Lists', [$folder('2026', [$file('a.pdf', 7)]), $file('b.pdf')])]]],
			['downloads' => ['protected' => true, 'files' => [$file('readme.txt'), $folder('Price Lists', [$file('b.pdf')]), $file('new.pdf', 3)]]],
		);

		expect($changes[0])->toMatchArray([
			'kind'    => 'files',
			'label'   => 'Downloads',
			'removed' => [['path' => 'Price Lists/2026', 'name' => 'a.pdf', 'size' => 7]],
			'added'   => [['path' => '', 'name' => 'new.pdf', 'size' => 3]],
		]);
	});

	test('a card shows one line for each child that changed, with the place its file sits', function (): void {
		$changes = ($this->compare)(
			['lead' => ['name' => 'Alice', 'photo' => ($this->image)('alice.jpg', 7)]],
			['lead' => ['name' => 'Alicia', 'photo' => ($this->image)('alicia.jpg', 8)]],
		);

		expect(($this->pick)($changes, 'kind', 'property', 'path', 'before', 'after'))->toBe([
			'Lead › Name'  => ['kind' => 'text', 'property' => 'lead', 'path' => '', 'before' => 'Alice', 'after' => 'Alicia'],
			'Lead › Photo' => ['kind' => 'file', 'property' => 'lead', 'path' => 'photo', 'before' => 'alice.jpg', 'after' => 'alicia.jpg'],
		]);
	});

	test('a deck shows changed children by item, and an item added or removed as its children coming or going', function (): void {
		$changes = ($this->compare)(
			['team' => [
				'alice' => ['id' => 'alice', 'name' => 'Alice', 'photo' => ($this->image)('alice.jpg')],
				'bob'   => ['id' => 'bob', 'name' => 'Bob', 'photo' => ($this->image)('bob.jpg')],
			]],
			['team' => [
				'alice' => ['id' => 'alice', 'name' => 'Alice', 'photo' => ($this->image)('new.jpg')],
				'carol' => ['id' => 'carol', 'name' => 'Carol'],
			]],
		);

		expect(($this->pick)($changes, 'kind', 'path', 'before', 'after'))->toBe([
			'Team › alice › Photo' => ['kind' => 'file', 'path' => 'alice/photo', 'before' => 'alice.jpg', 'after' => 'new.jpg'],
			'Team › bob › Name'    => ['kind' => 'text', 'path' => '', 'before' => 'Bob', 'after' => ''],
			'Team › bob › Photo'   => ['kind' => 'file', 'path' => 'bob/photo', 'before' => 'bob.jpg', 'after' => ''],
			'Team › carol › Name'  => ['kind' => 'text', 'path' => '', 'before' => '', 'after' => 'Carol'],
		]);
	});

	test('a video shows its link, and its poster as a file under the video', function (): void {
		$changes = ($this->compare)(
			['lead' => ['reel' => ['url' => 'https://youtu.be/one', 'poster' => ($this->image)('p1.jpg')]]],
			['lead' => ['reel' => ['url' => 'https://youtu.be/two', 'poster' => ($this->image)('p2.jpg')]]],
		);

		expect(($this->pick)($changes, 'kind', 'path', 'before', 'after'))->toBe([
			'Lead › Reel'          => ['kind' => 'text', 'path' => '', 'before' => 'https://youtu.be/one', 'after' => 'https://youtu.be/two'],
			'Lead › Reel › Poster' => ['kind' => 'file', 'path' => 'reel/poster', 'before' => 'p1.jpg', 'after' => 'p2.jpg'],
		]);
	});

	test('every change names its top-level property, so one field can be restored on its own', function (): void {
		$changes = ($this->compare)(
			['title' => 'a', 'team' => ['alice' => ['id' => 'alice', 'name' => 'A']]],
			['title' => 'b', 'team' => ['alice' => ['id' => 'alice', 'name' => 'B']]],
		);

		expect(array_column($changes, 'property'))->toBe(['title', 'team']);
	});
});
