<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\ImageWorks\Data\WarmVariants;
use TotalCMS\Domain\ImageWorks\Service\ImageWorksWarmer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

/**
 * `ImageWorksWarmer` walks a collection and makes the variants the public
 * /imageworks route would serve. The key tests let the warmer make files and
 * then hit the route (or the reverse), asserting on the `.cache` folders, so
 * "warmed" is pinned to the route's own cache key.
 *
 * Fixture: s1 has a photo and a two-image gallery, s2 a photo only — 4 images.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$c->get(SchemaSaver::class)->saveSchema(['id' => 'snaps', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
		'pics'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery', 'settings' => ['extractPalette' => false]],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'snaps', 'name' => 'Snaps', 'schema' => 'snaps']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's1', 'title' => 'One']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's2', 'title' => 'Two']);

	expect(postUpload('/api/collections/snaps/s1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/snaps/s1/pics', testData('test-image.jpg'), 'image/jpeg', 'pics')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/snaps/s1/pics', testData('image-exif.jpg'), 'image/jpeg', 'pics')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/snaps/s2/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200);

	$this->warmer = fn (): ImageWorksWarmer => $this->app->getContainer()->get(ImageWorksWarmer::class);

	$this->raw = fn (string $id): array => $this->app->getContainer()->get(ObjectFetcher::class)->fetchObjectFromDisk('snaps', $id)->toArray();

	$this->variants = fn (array $params): WarmVariants => WarmVariants::fromOptions([], [], false, $params, [], [], null);

	// Files under every property's .cache folder, recursively (Glide nests them).
	$this->cacheFiles = function (): int {
		$count = 0;
		foreach ([['s1', 'photo'], ['s1', 'pics'], ['s2', 'photo']] as [$id, $property]) {
			$dir = objectFilesPath('snaps', $id) . "/{$property}/.cache";
			if (!is_dir($dir)) {
				continue;
			}
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				$count += $file->isFile() ? 1 : 0;
			}
		}

		return $count;
	};

	// The route URL for every fixture image, with its extension swapped for $format.
	$this->routeUrls = function (string $format, string $query): array {
		$urls = ["/imageworks/snaps/s1/photo.{$format}?{$query}", "/imageworks/snaps/s2/photo.{$format}?{$query}"];
		foreach (($this->raw)('s1')['pics'] as $image) {
			$urls[] = '/imageworks/snaps/s1/pics/' . pathinfo($image['name'], PATHINFO_FILENAME) . ".{$format}?{$query}";
		}

		return $urls;
	};
});

test('warming makes what the route then serves', function (): void {
	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']));

	expect($report->objects)->toBe(2)
		->and($report->images)->toBe(4)
		->and($report->made)->toBe(4)
		->and($report->failed)->toBe(0);

	$before = ($this->cacheFiles)();
	expect($before)->toBeGreaterThanOrEqual(4);

	foreach (($this->routeUrls)('webp', 'w=400') as $url) {
		expect(get($url)->getStatusCode())->toBe(200);
	}

	expect(($this->cacheFiles)())->toBe($before);
});

test("the route's files count as cached", function (): void {
	expect(get('/imageworks/snaps/s1/photo.webp?w=400')->getStatusCode())->toBe(200);

	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']));

	expect($report->cached)->toBeGreaterThanOrEqual(1)
		->and($report->made)->toBe(3);
});

test('a second run makes nothing', function (): void {
	$variants = ($this->variants)(['w=400&fm=webp', 'w=300&fm=jpg']);

	($this->warmer)()->warm('snaps', null, $variants);
	$report = ($this->warmer)()->warm('snaps', null, $variants);

	expect($report->made)->toBe(0)
		->and($report->cached)->toBe(2 * 4);
});

test('dry run writes nothing', function (): void {
	$before = ($this->cacheFiles)();

	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp', 'w=300&fm=jpg']), dryRun: true);

	expect(($this->cacheFiles)())->toBe($before)
		->and($report->made)->toBe(0)
		->and($report->wouldMake)->toBe(4 * 2);
});

test('progress is reported per image against the counted total', function (): void {
	$calls = [];
	($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']), onImage: function (int $done, int $total) use (&$calls): void {
		$calls[] = [$done, $total];
	});

	expect($calls)->toBe([[1, 4], [2, 4], [3, 4], [4, 4]]);
});

test('a broken image is counted and the rest are still made', function (): void {
	$name = ($this->raw)('s2')['photo']['name'];
	unlink(objectFilesPath('snaps', 's2') . "/photo/{$name}");

	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']));

	expect($report->failed)->toBe(1)
		->and($report->made)->toBe(3)
		->and($report->failures)->toHaveCount(1)
		->and($report->failures[0]['collection'])->toBe('snaps')
		->and($report->failures[0]['id'])->toBe('s2')
		->and($report->failures[0]['property'])->toBe('photo')
		->and($report->failures[0]['image'])->toBe($name)
		->and($report->failures[0]['params'])->toBe('w=400&fm=webp')
		->and($report->failures[0]['error'])->not->toBe('');
});

test('ids limit the run', function (): void {
	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']), ['s2']);

	expect($report->objects)->toBe(1)
		->and($report->images)->toBe(1)
		->and($report->made)->toBe(1);
});

test('a named property limits the run', function (): void {
	$report = ($this->warmer)()->warm('snaps', 'pics', ($this->variants)(['w=400&fm=webp']));

	expect($report->images)->toBe(2)
		->and($report->made)->toBe(2);
});

test('an unknown id is a failure row', function (): void {
	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']), ['s1', 'nope']);

	expect($report->made)->toBe(3)
		->and($report->failed)->toBe(1)
		->and($report->failures[0]['id'])->toBe('nope')
		->and($report->failures[0]['error'])->toBe('Object not found');
});

test('an unreadable object is a failure row and the run goes on', function (): void {
	// Left in the index, gone from disk: a stale index entry.
	unlink(objectPath('snaps', 's2'));

	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']));

	expect($report->made)->toBe(3)
		->and($report->images)->toBe(3)
		->and($report->failed)->toBe(1)
		->and($report->failures[0]['id'])->toBe('s2')
		->and($report->failures[0]['property'])->toBe('')
		->and($report->failures[0]['image'])->toBe('')
		->and($report->failures[0]['error'])->not->toBe('');
});

test('a corrupt object file is a failure row and the run goes on', function (): void {
	file_put_contents(objectPath('snaps', 's2'), '{not json');

	$calls  = [];
	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']), onImage: function (int $done, int $total) use (&$calls): void {
		$calls[] = [$done, $total];
	});

	expect($report->made)->toBe(3)
		->and($report->failed)->toBe(1)
		->and($report->failures[0]['id'])->toBe('s2')
		->and($calls)->toBe([[1, 3], [2, 3], [3, 3]]);
});

test('a repeated id is warmed once', function (): void {
	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']), ['s2', 's2'], dryRun: true);

	expect($report->objects)->toBe(1)
		->and($report->images)->toBe(1)
		->and($report->wouldMake)->toBe(1);
});

test('gallery images sharing a basename are warmed once, as the route serves the first', function (): void {
	$dir = sys_get_temp_dir() . '/warm-basename-' . uniqid();
	mkdir($dir);
	$png = imagecreatetruecolor(40, 30);
	imagepng($png, "{$dir}/test-image.png");

	expect(postUpload('/api/collections/snaps/s1/pics', "{$dir}/test-image.png", 'image/png', 'pics')->getStatusCode())->toBe(200);
	unlink("{$dir}/test-image.png");
	rmdir($dir);

	$names = array_column(($this->raw)('s1')['pics'], 'name');
	expect($names)->toContain('test-image.jpg')->toContain('test-image.png');

	$report = ($this->warmer)()->warm('snaps', 'pics', ($this->variants)(['w=20']), dryRun: true);

	expect($report->images)->toBe(2)
		->and($report->wouldMake)->toBe(2);
});

test('the report serializes its counts and failures', function (): void {
	$report = ($this->warmer)()->warm('snaps', null, ($this->variants)(['w=400&fm=webp']), ['nope']);

	expect($report->toArray())->toMatchArray([
		'objects' => 0, 'images' => 0, 'made' => 0, 'wouldMake' => 0, 'cached' => 0,
		'original' => 0, 'uncacheable' => 0, 'failed' => 1,
	])->and($report->toArray()['failures'][0]['id'])->toBe('nope');
});

test('properties lists image and gallery fields', function (): void {
	expect(array_map(static fn (array $t): string => $t['field'], ($this->warmer)()->properties('snaps', null)))->toBe(['photo' => 'image', 'pics' => 'gallery'])
		->and(($this->warmer)()->properties('snaps', 'pics'))->toBe(['pics' => ['root' => 'pics', 'field' => 'gallery', 'child' => null, 'childField' => null]]);
});

test('no image properties is an error', function (): void {
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'plain', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
	], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'plain', 'name' => 'Plain', 'schema' => 'plain']);

	($this->warmer)()->properties('plain', null);
})->throws(InvalidArgumentException::class, 'Collection plain has no image or gallery properties.');

test('a non-image property is an error', function (): void {
	($this->warmer)()->properties('snaps', 'title');
})->throws(InvalidArgumentException::class, 'Property title in collection snaps is not an image, gallery, video, card or deck field.');
