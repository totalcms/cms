<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Service\CollectionFileRepairService;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\delete;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;
use function TotalCMS\Slim\Pest\put;
use function TotalCMS\Slim\Pest\putJson;

/**
 * The nested-upload matrix, end to end through the real routes: an `image`,
 * a `file` and a `video` (its poster) child inside a card AND inside a deck
 * item. For each cell: upload writes the child back at the right path and
 * leaves its siblings alone, the bytes land in the right directory, the
 * public routes serve them back, a nested PUT edits the child's meta, a
 * nested DELETE clears the child and its directory, a re-upload replaces the
 * file without touching sibling directories, and repair rebuilds a child
 * whose data was blanked. The unit tests pin the individual services; this
 * file is the regression net for the whole chain, because the video work
 * changed the path walk that image and file rely on too.
 *
 * Paths (URL and disk are the same shape):
 *   card       mycard/photo         mycard/doc         mycard/promo/poster
 *   deck item  mydeck/one/photo     mydeck/one/doc     mydeck/one/promo/poster
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c       = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);
	$schemas->saveSchema(['id' => 'widget-card', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'label' => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
		'doc'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
		'promo' => ['type' => 'video', 'field' => 'video'],
	]]);
	$ref = 'https://www.totalcms.co/schemas/custom/widget-card.json';
	$schemas->saveSchema(['id' => 'widgets', 'type' => 'object', 'properties' => [
		'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'  => ['type' => 'string', 'field' => 'text'],
		'mycard' => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => $ref],
		'mydeck' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => $ref],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'widgets', 'name' => 'Widgets', 'schema' => 'widgets']);

	$this->saver   = $c->get(ObjectSaver::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
	$this->saver->saveObject('widgets', [
		'id'     => 'w1',
		'title'  => 'Widget',
		'mycard' => ['label' => 'Card label', 'promo' => ['url' => 'https://youtu.be/abc123XYZ_-']],
		'mydeck' => ['one' => ['id' => 'one', 'label' => 'Item label', 'promo' => ['url' => 'https://vimeo.com/123456789']]],
	]);
});

/** A small text file to upload as a `file` child. */
function nestedDocFixture(): string
{
	$path = sys_get_temp_dir() . '/nested-doc-' . uniqid() . '.txt';
	file_put_contents($path, "hello nested\n");

	return $path;
}

/** The stored object, straight from disk. */
function widget(): array
{
	return test()->fetcher->fetchObjectFromDisk('widgets', 'w1')->toArray();
}

/** Follow `mycard/photo` or `mydeck/one/photo` through the stored object. */
function nestedValue(array $object, string $path): mixed
{
	$cursor = $object;
	foreach (explode('/', $path) as $segment) {
		$cursor = is_array($cursor) ? ($cursor[$segment] ?? null) : null;
	}

	return $cursor;
}

// Every cell of the matrix: [container label, path prefix, sibling text path].
$containers = [
	'card'      => ['mycard', 'mycard/label', 'Card label'],
	'deck item' => ['mydeck/one', 'mydeck/one/label', 'Item label'],
];

// ─── image ───────────────────────────────────────────────────────────────────

test('image inside a {container}: upload writes it back at the nested path, keeps siblings, serves it through ImageWorks', function (string $prefix, string $siblingPath, string $siblingValue): void {
	$response = postUpload("/api/collections/widgets/w1/{$prefix}/photo", testData('test-image.jpg'), 'image/jpeg', 'photo');
	expect($response->getStatusCode())->toBe(200);

	$object = widget();
	$photo  = nestedValue($object, "{$prefix}/photo");
	expect($photo['name'] ?? '')->toBe('test-image.jpg')
		->and($photo['width'] ?? 0)->toBeGreaterThan(0)
		->and($photo['mime'] ?? '')->toBe('image/jpeg')
		->and(nestedValue($object, $siblingPath))->toBe($siblingValue)
		// The video sibling in the same container is untouched too.
		->and(nestedValue($object, "{$prefix}/promo")['provider'] ?? '')->not->toBe('');

	$dir = objectFilesPath('widgets', 'w1') . "/{$prefix}/photo";
	expect(is_file("{$dir}/test-image.jpg"))->toBeTrue();

	expect(get("/imageworks/widgets/w1/{$prefix}/photo.jpg?w=40")->getStatusCode())->toBe(200)
		->and(get("/api/upload/widgets/w1/{$prefix}/photo/test-image.jpg")->getStatusCode())->toBe(200);
})->with($containers);

test('image inside a {container}: a nested PUT replaces the child meta and a nested DELETE clears it and its directory', function (string $prefix, string $siblingPath, string $siblingValue): void {
	postUpload("/api/collections/widgets/w1/{$prefix}/photo", testData('test-image.jpg'), 'image/jpeg', 'photo');
	$before = nestedValue(widget(), "{$prefix}/photo");

	// PUT on the nested path replaces the child wholesale (the admin sends
	// the full image object back), so send the stored child with a new alt.
	$response = putJson("/api/collections/widgets/w1/{$prefix}/photo", array_merge($before, ['alt' => 'Nested alt']));
	expect($response->getStatusCode())->toBe(200);
	$after = nestedValue(widget(), "{$prefix}/photo");
	expect($after['alt'] ?? '')->toBe('Nested alt')
		->and($after['name'] ?? '')->toBe('test-image.jpg')
		->and(nestedValue(widget(), $siblingPath))->toBe($siblingValue);

	$response = delete("/api/collections/widgets/w1/{$prefix}/photo");
	expect($response->getStatusCode())->toBe(200);
	$object = widget();
	// A cleared child comes back as the sub-schema's empty shape (the card/deck
	// factory regenerates every child on save), so "cleared" means no name.
	expect(nestedValue($object, "{$prefix}/photo")['name'] ?? '')->toBe('')
		->and(nestedValue($object, $siblingPath))->toBe($siblingValue)
		->and(is_dir(objectFilesPath('widgets', 'w1') . "/{$prefix}/photo"))->toBeFalse();
})->with($containers);

test('image inside a {container}: re-uploading replaces the file and leaves the sibling file child alone', function (string $prefix): void {
	postUpload("/api/collections/widgets/w1/{$prefix}/photo", testData('test-image.jpg'), 'image/jpeg', 'photo');
	postUpload("/api/collections/widgets/w1/{$prefix}/doc", nestedDocFixture(), 'text/plain', 'doc');

	$second = sys_get_temp_dir() . '/second-' . uniqid() . '.jpg';
	copy(testData('test-image.jpg'), $second);
	postUpload("/api/collections/widgets/w1/{$prefix}/photo", $second, 'image/jpeg', 'photo');

	$photoDir = objectFilesPath('widgets', 'w1') . "/{$prefix}/photo";
	$docDir   = objectFilesPath('widgets', 'w1') . "/{$prefix}/doc";
	expect(glob("{$photoDir}/*"))->toHaveCount(1)
		->and(nestedValue(widget(), "{$prefix}/photo")['name'] ?? '')->toBe(basename($second))
		->and(glob("{$docDir}/*"))->toHaveCount(1)
		->and(nestedValue(widget(), "{$prefix}/doc")['name'] ?? '')->not->toBe('');
})->with($containers);

// ─── file ────────────────────────────────────────────────────────────────────

test('file inside a {container}: upload writes it back, keeps siblings, serves it through the download route', function (string $prefix, string $siblingPath, string $siblingValue): void {
	$fixture  = nestedDocFixture();
	$response = postUpload("/api/collections/widgets/w1/{$prefix}/doc", $fixture, 'text/plain', 'doc');
	expect($response->getStatusCode())->toBe(200);

	$object = widget();
	$doc    = nestedValue($object, "{$prefix}/doc");
	expect($doc['name'] ?? '')->toBe(basename($fixture))
		->and((int)($doc['size'] ?? 0))->toBeGreaterThan(0)
		->and(nestedValue($object, $siblingPath))->toBe($siblingValue);

	expect(is_file(objectFilesPath('widgets', 'w1') . "/{$prefix}/doc/" . basename($fixture)))->toBeTrue();
	expect(get("/download/upload/widgets/w1/{$prefix}/doc/" . basename($fixture))->getStatusCode())->toBe(200);
})->with($containers);

test('file inside a {container}: a nested DELETE clears the child and its directory', function (string $prefix, string $siblingPath, string $siblingValue): void {
	$fixture = nestedDocFixture();
	postUpload("/api/collections/widgets/w1/{$prefix}/doc", $fixture, 'text/plain', 'doc');

	expect(delete("/api/collections/widgets/w1/{$prefix}/doc")->getStatusCode())->toBe(200);
	$object = widget();
	expect(nestedValue($object, "{$prefix}/doc")['name'] ?? '')->toBe('')
		->and(nestedValue($object, $siblingPath))->toBe($siblingValue)
		->and(is_dir(objectFilesPath('widgets', 'w1') . "/{$prefix}/doc"))->toBeFalse();
})->with($containers);

// ─── video poster ────────────────────────────────────────────────────────────

test('video poster inside a {container}: upload lands one level deeper, keeps the video keys and siblings, serves through ImageWorks', function (string $prefix, string $siblingPath, string $siblingValue): void {
	$response = postUpload("/api/collections/widgets/w1/{$prefix}/promo/poster", testData('test-image.jpg'), 'image/jpeg', 'poster');
	expect($response->getStatusCode())->toBe(200);

	$object = widget();
	$promo  = nestedValue($object, "{$prefix}/promo");
	expect($promo['poster']['name'] ?? '')->toBe('test-image.jpg')
		->and($promo['url'] ?? '')->not->toBe('')
		->and($promo['provider'] ?? '')->not->toBe('')
		->and(nestedValue($object, $siblingPath))->toBe($siblingValue);

	expect(is_file(objectFilesPath('widgets', 'w1') . "/{$prefix}/promo/poster/test-image.jpg"))->toBeTrue();
	expect(get("/imageworks/widgets/w1/{$prefix}/promo/poster.jpg?w=40")->getStatusCode())->toBe(200);
})->with($containers);

test('video poster inside a {container}: a nested DELETE clears the poster but not the video', function (string $prefix): void {
	postUpload("/api/collections/widgets/w1/{$prefix}/promo/poster", testData('test-image.jpg'), 'image/jpeg', 'poster');

	expect(delete("/api/collections/widgets/w1/{$prefix}/promo/poster")->getStatusCode())->toBe(200);
	$promo = nestedValue(widget(), "{$prefix}/promo");
	expect($promo['poster']['name'] ?? '')->toBe('')
		->and($promo['url'] ?? '')->not->toBe('')
		->and(is_dir(objectFilesPath('widgets', 'w1') . "/{$prefix}/promo/poster"))->toBeFalse();
})->with($containers);

// ─── whole-object writes ─────────────────────────────────────────────────────

test('a full object save that carries the nested children back keeps their uploads in a {container}', function (string $prefix): void {
	postUpload("/api/collections/widgets/w1/{$prefix}/photo", testData('test-image.jpg'), 'image/jpeg', 'photo');
	postUpload("/api/collections/widgets/w1/{$prefix}/doc", nestedDocFixture(), 'text/plain', 'doc');
	postUpload("/api/collections/widgets/w1/{$prefix}/promo/poster", testData('test-image.jpg'), 'image/jpeg', 'poster');

	// The admin form PUTs the whole object, children included, with an
	// unrelated edit — nothing nested may be lost.
	$object          = widget();
	$object['title'] = 'Edited';
	expect(putJson('/api/collections/widgets/w1', $object)->getStatusCode())->toBe(200);

	$after = widget();
	expect($after['title'])->toBe('Edited')
		->and(nestedValue($after, "{$prefix}/photo")['name'] ?? '')->toBe('test-image.jpg')
		->and(nestedValue($after, "{$prefix}/doc")['name'] ?? '')->not->toBe('')
		->and(nestedValue($after, "{$prefix}/promo")['poster']['name'] ?? '')->toBe('test-image.jpg')
		->and(nestedValue($after, "{$prefix}/promo")['provider'] ?? '')->not->toBe('');
})->with($containers);

test('removing a deck item from the object deletes its uploaded files', function (): void {
	postUpload('/api/collections/widgets/w1/mydeck/one/photo', testData('test-image.jpg'), 'image/jpeg', 'photo');
	postUpload('/api/collections/widgets/w1/mydeck/one/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');
	$itemDir = objectFilesPath('widgets', 'w1') . '/mydeck/one';
	expect(is_dir($itemDir))->toBeTrue();

	$object           = widget();
	$object['mydeck'] = [];
	expect(putJson('/api/collections/widgets/w1', $object)->getStatusCode())->toBe(200);

	expect(is_dir($itemDir))->toBeFalse();
});

// ─── repair ──────────────────────────────────────────────────────────────────

test('repair:files rebuilds a blanked image, file and video poster inside a {container} from the files left on disk', function (string $prefix): void {
	postUpload("/api/collections/widgets/w1/{$prefix}/photo", testData('test-image.jpg'), 'image/jpeg', 'photo');
	postUpload("/api/collections/widgets/w1/{$prefix}/doc", nestedDocFixture(), 'text/plain', 'doc');
	postUpload("/api/collections/widgets/w1/{$prefix}/promo/poster", testData('test-image.jpg'), 'image/jpeg', 'poster');

	// A PUT that dropped every nested file child (the failure repair exists for).
	$object   = widget();
	$segments = explode('/', $prefix);
	$cursor   =&$object;
	foreach ($segments as $segment) {
		$cursor =&$cursor[$segment];
	}
	unset($cursor['photo'], $cursor['doc'], $cursor['promo']['poster']);
	unset($cursor);
	$this->app->getContainer()->get(ObjectUpdater::class)->updateObject('widgets', 'w1', $object);
	expect(nestedValue(widget(), "{$prefix}/photo")['name'] ?? '')->toBe('');

	$report  = $this->app->getContainer()->get(CollectionFileRepairService::class)->apply('widgets', new RepairFilters());
	$applied = array_map(fn ($c) => $c->subpath, array_filter($report->candidates, fn ($c) => $c->applied === true));
	$sub     = str_contains($prefix, '/') ? substr($prefix, strpos($prefix, '/') + 1) . '/' : '';
	expect($applied)->toContain("{$sub}photo", "{$sub}doc", "{$sub}promo/poster");

	$after = widget();
	expect(nestedValue($after, "{$prefix}/photo")['name'] ?? '')->toBe('test-image.jpg')
		->and(nestedValue($after, "{$prefix}/doc")['name'] ?? '')->not->toBe('')
		->and(nestedValue($after, "{$prefix}/promo")['poster']['name'] ?? '')->toBe('test-image.jpg');
})->with($containers);

// ─── admin form ──────────────────────────────────────────────────────────────

test('the admin object form addresses nested children by their full dotted path (card, deck item, and a video poster in each)', function (): void {
	postUpload('/api/collections/widgets/w1/mycard/photo', testData('test-image.jpg'), 'image/jpeg', 'photo');
	postUpload('/api/collections/widgets/w1/mydeck/one/photo', testData('test-image.jpg'), 'image/jpeg', 'photo');
	postUpload('/api/collections/widgets/w1/mycard/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');
	postUpload('/api/collections/widgets/w1/mydeck/one/promo/poster', testData('test-image.jpg'), 'image/jpeg', 'poster');

	signInAs($this->app, 'admin-user-test-com', 'auth');
	$response = get('/admin/collections/widgets/w1');
	$response->assertOk();
	$html = (string)$response->getBody();

	// ImageField builds its preview from `{nestedPath}.{name}`, which is the
	// same path the upload, ImageWorks and delete routes use — so if any of
	// these are missing, the form would upload to (or preview from) the wrong
	// place. The poster's path goes through VideoField::buildMedia().
	foreach (['mycard/photo', 'mydeck/one/photo', 'mycard/promo/poster', 'mydeck/one/promo/poster'] as $path) {
		expect($html)->toContain("/imageworks/widgets/w1/{$path}");
	}
});
