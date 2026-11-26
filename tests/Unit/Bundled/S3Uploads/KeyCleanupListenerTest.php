<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\S3Uploads\Lifecycle\KeyCleanupListener;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Support\HttpResponse;

require_once __DIR__ . '/helpers.php';

/** @return array<string,mixed> */
function s3Ptr(string $key): array
{
	return ['id' => (string)KeyBuilder::idFromKey($key), 'key' => $key, 'name' => basename($key), 'size' => 1, 'mime' => 'application/pdf'];
}

describe('KeyCleanupListener', function (): void {
	$own = fn (string $property, string $file, string $object = 'post-1'): string => "site/blog/{$object}/{$property}/{$file}";

	/** @param list<HttpResponse> $responses */
	$build = function (TestCase $test, array $responses = [], array $settings = []): object {
		$kit          = new stdClass();
		$kit->http    = new RecordingHttpClient(...$responses);
		$kit->logger  = new RecordingLogger();
		$kit->datadir = tcmsTestTempDir('s3-cleanup');
		$kit->counter = s3Counter($kit->datadir);
		$kit->patched = [];

		$patcher = (fn () => $this->createMock(ObjectPatcher::class))->call($test);
		$patcher->method('patchObject')->willReturnCallback(function (string $collection, string $id, array $data) use ($kit): ObjectData {
			$kit->patched[] = [$collection, $id, $data];
			// The real patcher saves the object, which fires object.updated.
			$kit->listener->onUpdated([
				'collection' => $collection,
				'id'         => $id,
				'object'     => s3Object($id, $data),
				'previous'   => $kit->beforePatch,
			]);

			return s3Object($id, $data);
		});

		$kit->listener = new KeyCleanupListener(
			s3Settings($settings),
			new S3Fields(s3SchemaFetcher($test, s3TestProperties())),
			new KeyBuilder('site/'),
			s3Client($kit->http),
			$kit->counter,
			$patcher,
			$kit->logger,
		);

		return $kit;
	};

	$deleted = fn (object $kit): array => array_map(
		fn (array $r): string => rawurldecode((string)parse_url($r['url'], PHP_URL_PATH)),
		array_values(array_filter($kit->http->requests, fn (array $r): bool => $r['method'] === 'DELETE')),
	);

	$updated = fn (array $before, array $after): array => [
		'collection' => 'blog', 'id' => 'post-1', 'object' => s3Object('post-1', $after), 'previous' => s3Object('post-1', $before),
	];

	test('replacing a file deletes the old key only', function () use ($build, $deleted, $updated, $own): void {
		$kit = $build($this, [new HttpResponse(204, '')]);
		$kit->listener->onUpdated($updated(
			['brochure' => s3Ptr($own('brochure', '0a1b2c3d-old.pdf'))],
			['brochure' => s3Ptr($own('brochure', '1a1b2c3d-new.pdf'))],
		));

		expect($deleted($kit))->toBe(['/' . $own('brochure', '0a1b2c3d-old.pdf')])
			->and($kit->http->requests)->toHaveCount(1);
	});

	test('clearing a file deletes its key', function () use ($build, $deleted, $updated, $own): void {
		$kit = $build($this, [new HttpResponse(204, '')]);
		$kit->listener->onUpdated($updated(['brochure' => s3Ptr($own('brochure', '0a1b2c3d-old.pdf'))], ['brochure' => null]));

		expect($deleted($kit))->toBe(['/' . $own('brochure', '0a1b2c3d-old.pdf')]);
	});

	test('an unchanged file is left alone', function () use ($build, $updated, $own): void {
		$kit   = $build($this);
		$value = ['brochure' => s3Ptr($own('brochure', '0a1b2c3d-a.pdf'))];
		$kit->listener->onUpdated($updated($value, $value + ['title' => 'New title']));

		expect($kit->http->requests)->toBe([]);
	});

	test('a key this object does not own is never deleted', function (string $property, string $key) use ($build, $updated): void {
		$kit = $build($this);
		$kit->listener->onUpdated($updated([$property => s3Ptr($key)], [$property => null]));

		expect($kit->http->requests)->toBe([]);
	})->with([
		'another object\'s path'   => ['brochure', 'site/blog/post-2/brochure/0a1b2c3d-a.pdf'],
		'another property\'s path' => ['brochure', 'site/blog/post-1/private/0a1b2c3d-a.pdf'],
		'outside the prefix'       => ['brochure', 'other/blog/post-1/brochure/0a1b2c3d-a.pdf'],
		'dot dot in the key'       => ['brochure', 'site/blog/post-1/brochure/../../../x/0a1b2c3d-a.pdf'],
		'a folder property'        => ['shared', 'site/downloads/0a1b2c3d-a.pdf'],
	]);

	test('a key that moved to another s3 property of the object is kept', function () use ($build, $updated, $own): void {
		$kit = $build($this);
		$ptr = s3Ptr($own('brochure', '0a1b2c3d-a.pdf'));
		$kit->listener->onUpdated($updated(['brochure' => $ptr], ['brochure' => null, 'private' => $ptr]));

		expect($kit->http->requests)->toBe([]);
	});

	test('removing one depot file deletes just that key', function () use ($build, $deleted, $updated, $own): void {
		$kit  = $build($this, [new HttpResponse(204, '')]);
		$keep = s3Ptr($own('files', '0a1b2c3d-keep.pdf'));
		$drop = s3Ptr($own('files', '1a1b2c3d-drop.pdf'));
		$kit->listener->onUpdated($updated(['files' => [$keep, $drop]], ['files' => [$keep]]));

		expect($deleted($kit))->toBe(['/' . $own('files', '1a1b2c3d-drop.pdf')]);
	});

	test('a count belongs to the stored file: replacing or clearing the file starts it again', function (mixed $after) use ($build, $updated, $own): void {
		$kit = $build($this, [new HttpResponse(204, '')]);
		$kit->counter->increment('blog', 'post-1', 'brochure');
		$kit->counter->increment('blog', 'post-1', 'brochure');
		$kit->counter->increment('blog', 'post-1', 'private');

		$kit->listener->onUpdated($updated(
			['brochure' => s3Ptr($own('brochure', '0a1b2c3d-old.pdf')), 'private' => s3Ptr($own('private', '2a1b2c3d-p.pdf'))],
			['brochure' => $after, 'private' => s3Ptr($own('private', '2a1b2c3d-p.pdf'))],
		));

		// The other property's file did not change, so its count stays.
		expect($kit->counter->forObject('blog', 'post-1'))->toBe(['private' => 1]);
	})->with([
		'replaced' => [s3Ptr('site/blog/post-1/brochure/1a1b2c3d-new.pdf')],
		'cleared'  => [null],
	]);

	test('a save that leaves the file alone keeps its count', function () use ($build, $updated, $own): void {
		$kit   = $build($this);
		$value = ['brochure' => s3Ptr($own('brochure', '0a1b2c3d-a.pdf'))];
		$kit->counter->increment('blog', 'post-1', 'brochure');

		$kit->listener->onUpdated($updated($value, $value + ['title' => 'New title']));

		expect($kit->counter->forObject('blog', 'post-1'))->toBe(['brochure' => 1]);
	});

	test('removing one depot file drops that file\'s count and keeps the others', function () use ($build, $updated, $own): void {
		$kit  = $build($this, [new HttpResponse(204, '')]);
		$keep = s3Ptr($own('files', '0a1b2c3d-keep.pdf'));
		$drop = s3Ptr($own('files', '1a1b2c3d-drop.pdf'));
		$kit->counter->increment('blog', 'post-1', 'files/0a1b2c3d');
		$kit->counter->increment('blog', 'post-1', 'files/1a1b2c3d');

		$kit->listener->onUpdated($updated(['files' => [$keep, $drop]], ['files' => [$keep]]));

		expect($kit->counter->forObject('blog', 'post-1'))->toBe(['files/0a1b2c3d' => 1]);
	});

	test('with no counts on file, a changed file writes no count file', function () use ($build, $updated, $own): void {
		$kit = $build($this, [new HttpResponse(204, '')]);
		$kit->listener->onUpdated($updated(['brochure' => s3Ptr($own('brochure', '0a1b2c3d-old.pdf'))], ['brochure' => null]));

		expect(glob($kit->datadir . '/.system/extension-data/totalcms/s3-uploads/counts/blog/*') ?: [])->toBe([]);
	});

	test('deleting an object deletes every key it owns and forgets its counts', function () use ($build, $deleted, $own): void {
		$kit = $build($this, [new HttpResponse(204, ''), new HttpResponse(204, ''), new HttpResponse(204, '')]);
		$kit->counter->increment('blog', 'post-1', 'brochure');

		$kit->listener->onDeleted(['collection' => 'blog', 'id' => 'post-1', 'previous' => s3Object('post-1', [
			'brochure' => s3Ptr($own('brochure', '0a1b2c3d-a.pdf')),
			'shared'   => s3Ptr('site/downloads/2a1b2c3d-shared.pdf'),
			'files'    => [s3Ptr($own('files', '1a1b2c3d-b.pdf')), s3Ptr('site/blog/post-9/files/3a1b2c3d-foreign.pdf')],
		])]);

		expect($deleted($kit))->toBe(['/' . $own('brochure', '0a1b2c3d-a.pdf'), '/' . $own('files', '1a1b2c3d-b.pdf')])
			->and($kit->counter->forObject('blog', 'post-1'))->toBe([]);
	});

	test('a delete with no previous object does nothing', function () use ($build): void {
		$kit = $build($this);
		$kit->listener->onDeleted(['collection' => 'blog', 'id' => 'post-1']);
		$kit->listener->onUpdated(['collection' => 'blog', 'id' => 'post-1', 'object' => s3Object('post-1', [])]);

		expect($kit->http->requests)->toBe([]);
	});

	test('a bucket failure during delete is logged and swallowed', function () use ($build, $updated, $own): void {
		$kit = $build($this, [new HttpResponse(500, '<Error><Code>InternalError</Code><Message>x</Message></Error>')]);
		$kit->listener->onUpdated($updated(['brochure' => s3Ptr($own('brochure', '0a1b2c3d-old.pdf'))], ['brochure' => null]));

		expect($kit->logger->records)->toHaveCount(1)
			->and($kit->logger->records[0]['message'])->toContain('InternalError');
	});

	test('a clone gets its own copy of each file', function () use ($build, $own): void {
		$kit      = $build($this, [new HttpResponse(200, '<CopyObjectResult/>')]);
		$original = s3Ptr($own('brochure', '0a1b2c3d-a.pdf'));
		$shared   = s3Ptr('site/downloads/2a1b2c3d-shared.pdf');
		$clone    = s3Object('post-2', ['brochure' => $original, 'shared' => $shared]);

		$kit->beforePatch = $clone;
		$kit->listener->onCreated(['collection' => 'blog', 'id' => 'post-2', 'object' => $clone]);

		$copy = $kit->http->requests[0];
		$new  = $kit->patched[0][2]['brochure'];

		expect($kit->http->methods())->toBe(['PUT'])
			->and($copy['options']['headers']['x-amz-copy-source'])->toBe('/my-bucket/' . $own('brochure', '0a1b2c3d-a.pdf'))
			->and($kit->patched)->toHaveCount(1)
			->and(array_keys($kit->patched[0][2]))->toBe(['brochure'])
			->and($new['key'])->toMatch('#^site/blog/post-2/brochure/[0-9a-f]{8}-0a1b2c3d-a\.pdf$#')
			->and($new['key'])->not->toBe($original['key'])
			->and($new['id'])->toBe(KeyBuilder::idFromKey($new['key']))
			->and($new['name'])->toBe($original['name'])
			->and($copy['url'])->toEndWith('/' . $new['key']);
	});

	test('a new object with its own files or none is not patched', function () use ($build, $own): void {
		$kit = $build($this);
		$kit->listener->onCreated(['collection' => 'blog', 'id' => 'post-1', 'object' => s3Object('post-1', [
			'brochure' => s3Ptr($own('brochure', '0a1b2c3d-a.pdf')),
			'private'  => null,
			'files'    => [],
		])]);

		expect($kit->http->requests)->toBe([])->and($kit->patched)->toBe([]);
	});

	test('a pointer outside the prefix is not copied', function () use ($build): void {
		$kit = $build($this);
		$kit->listener->onCreated(['collection' => 'blog', 'id' => 'post-2', 'object' => s3Object('post-2', [
			'brochure' => s3Ptr('other/blog/post-1/brochure/0a1b2c3d-a.pdf'),
		])]);

		expect($kit->http->requests)->toBe([])->and($kit->patched)->toBe([]);
	});

	test('a failed copy is logged and leaves the pointer shared', function () use ($build, $own): void {
		$kit   = $build($this, [new HttpResponse(500, '<Error><Code>InternalError</Code><Message>x</Message></Error>')]);
		$clone = s3Object('post-2', ['brochure' => s3Ptr($own('brochure', '0a1b2c3d-a.pdf'))]);
		$kit->listener->onCreated(['collection' => 'blog', 'id' => 'post-2', 'object' => $clone]);

		expect($kit->patched)->toBe([])
			->and($kit->logger->records)->toHaveCount(1)
			->and($kit->logger->records[0]['message'])->toContain('post-2');
	});

	test('a depot clone copies every file and keeps their order', function () use ($build, $own): void {
		$kit   = $build($this, [new HttpResponse(200, ''), new HttpResponse(200, '')]);
		$clone = s3Object('post-2', ['files' => [s3Ptr($own('files', '0a1b2c3d-a.pdf')), s3Ptr($own('files', '1a1b2c3d-b.pdf'))]]);

		$kit->beforePatch = $clone;
		$kit->listener->onCreated(['collection' => 'blog', 'id' => 'post-2', 'object' => $clone]);

		$files = $kit->patched[0][2]['files'];

		expect($kit->http->methods())->toBe(['PUT', 'PUT'])
			->and(array_column($files, 'name'))->toBe(['0a1b2c3d-a.pdf', '1a1b2c3d-b.pdf'])
			->and($files[0]['key'])->toStartWith('site/blog/post-2/files/')
			->and($files[1]['key'])->toStartWith('site/blog/post-2/files/');
	});

	test('a stale save that puts an older file back does not delete the newer one', function () use ($build, $updated, $own): void {
		$kit   = $build($this);
		$older = s3Ptr($own('brochure', '0a1b2c3d-old.pdf')) + ['uploadedAt' => '2026-09-05T14:00:00+00:00'];
		$newer = s3Ptr($own('brochure', '1a1b2c3d-new.pdf')) + ['uploadedAt' => '2026-09-05T15:00:00+00:00'];

		// A form loaded before the replacement saves, carrying the old pointer.
		$kit->listener->onUpdated($updated(['brochure' => $newer], ['brochure' => $older]));

		expect($kit->http->requests)->toBe([])
			->and($kit->logger->records)->toHaveCount(1)
			->and($kit->logger->records[0]['message'])->toContain('older file');
	});

	test('replacing a file with a newer one still deletes the old key when both carry dates', function () use ($build, $deleted, $updated, $own): void {
		$kit   = $build($this, [new HttpResponse(204, '')]);
		$older = s3Ptr($own('brochure', '0a1b2c3d-old.pdf')) + ['uploadedAt' => '2026-09-05T14:00:00+00:00'];
		$newer = s3Ptr($own('brochure', '1a1b2c3d-new.pdf')) + ['uploadedAt' => '2026-09-05T15:00:00+00:00'];

		$kit->listener->onUpdated($updated(['brochure' => $older], ['brochure' => $newer]));

		expect($deleted($kit))->toBe(['/' . $own('brochure', '0a1b2c3d-old.pdf')]);
	});

	test('with no bucket configured nothing is attempted', function () use ($build, $updated, $own): void {
		$kit = $build($this, [], ['bucket' => '']);
		$ptr = s3Ptr($own('brochure', '0a1b2c3d-old.pdf'));

		$kit->listener->onUpdated($updated(['brochure' => $ptr], ['brochure' => null]));
		$kit->listener->onDeleted(['collection' => 'blog', 'id' => 'post-1', 'previous' => s3Object('post-1', ['brochure' => $ptr])]);
		$kit->listener->onCreated(['collection' => 'blog', 'id' => 'post-2', 'object' => s3Object('post-2', ['brochure' => $ptr])]);

		expect($kit->http->requests)->toBe([])->and($kit->patched)->toBe([])->and($kit->logger->records)->toBe([]);
	});
});
