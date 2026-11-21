<?php

declare(strict_types=1);

use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Bundled\S3Uploads\Upload\UploadError;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\JsonData;
use TotalCMS\Support\HttpResponse;

require_once __DIR__ . '/helpers.php';

// presign() hands the browser a link for one key; commit() checks what
// arrived in the bucket and writes the pointer to the object.

describe('UploadService::presign', function (): void {
	test('refusals come in a fixed order, each with its own code', function (array $with, int $status, string $code, string $message): void {
		$service = s3UploadService($this, $with)->service;

		try {
			$service->presign('blog', 'post-1', $with['property'] ?? 'brochure', 'Brochure 2026.pdf', $with['size'] ?? 2048, 'application/pdf');
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe($status)->and($e->errorCode)->toBe($code)->and($e->getMessage())->toBe($message);
		}
	})->with([
		'unlicensed'       => [['licensed' => false, 'settings' => ['bucket' => '']], 403, 's3.unlicensed', 'S3 Uploads needs Total CMS Pro.'],
		'unconfigured'     => [['settings' => ['bucket' => ''], 'property' => 'nope'], 503, 's3.unconfigured', 'S3 Uploads is not set up yet. A super admin can add the bucket in Extensions → S3 Uploads.'],
		'not an s3 field'  => [['property' => 'title', 'exists' => false], 400, 's3.bad_property', 'title is not an S3 file field.'],
		'nested property'  => [['property' => 'clip'], 400, 's3.bad_property', 'clip is not an S3 file field.'],
		'missing object'   => [['exists' => false, 'canUpdate' => false], 404, 's3.no_object', 'Save the object before uploading.'],
		'no update access' => [['canUpdate' => false, 'size' => 99999999], 403, 's3.forbidden', 'You do not have permission to upload files to blog.'],
		'signed out'       => [['user' => null], 403, 's3.forbidden', 'You do not have permission to upload files to blog.'],
		'rules'            => [['size' => 2048001], 422, 's3.too_large', 'Brochure 2026.pdf is 2 MB. The limit for this field is 2 MB.'],
	]);

	test('success returns a PUT link for a key under the object, and a token bound to the upload', function (): void {
		$kit    = s3UploadService($this);
		$result = $kit->service->presign('blog', 'post-1', 'brochure', 'Brochure 2026.pdf', 2048, 'application/pdf');

		expect($result['method'])->toBe('PUT')
			->and($result['headers'])->toBe(['Content-Type' => 'application/pdf'])
			->and($result['key'])->toMatch('#^site/blog/post-1/brochure/[0-9a-f]{8}-brochure-2026\.pdf$#')
			->and($result['url'])->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/' . $result['key'] . '?')
			->and($result['url'])->toContain('X-Amz-Expires=900')
			->and($result['url'])->toContain('X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost')
			->and($kit->tokens->verify('s3-upload', $result['token']))->toBe([
				'c' => 'blog', 'i' => 'post-1', 'p' => 'brochure', 'k' => $result['key'],
				's' => 2048, 'm' => 'application/pdf', 'n' => 'Brochure 2026.pdf', 'u' => 'joe',
			])
			->and($kit->tokens->verify('unsubscribe', $result['token']))->toBeNull()
			->and($kit->http->requests)->toBe([]);
	});

	test('a file with no type is announced as a plain byte stream', function (): void {
		$kit    = s3UploadService($this);
		$result = $kit->service->presign('blog', 'post-1', 'files', 'data', 10, '');

		expect($result['headers'])->toBe(['Content-Type' => 'application/octet-stream'])
			->and($kit->tokens->verify('s3-upload', $result['token'])['m'] ?? null)->toBe('application/octet-stream');
	});

	test('the upload token lasts a day, so a slow upload of a big file can still commit', function (): void {
		$found = new HttpResponse(200, '', ['content-length' => '2048', 'content-type' => 'application/pdf']);
		$kit   = s3UploadService($this, ['responses' => [$found]]);
		$slow  = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];
		$late  = $kit->service->presign('blog', 'post-1', 'brochure', 'b.pdf', 2048, 'application/pdf')['token'];

		$kit->clock->now += 7200;
		expect($kit->service->commit($slow, null)['item']['name'])->toBe('a.pdf');

		$kit->clock->now += 86400;
		expect(fn () => $kit->service->commit($late, null))->toThrow(UploadError::class, 'This upload expired. Try again.');
	});

	test('in a collection where members own their objects, only the owner gets an upload link', function (): void {
		$service = s3UploadService($this, ['owns' => false])->service;

		try {
			$service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf');
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(403)->and($e->errorCode)->toBe('s3.forbidden');
		}
	});

	test('on a site with authentication switched off, an upload needs nobody signed in', function (): void {
		// No user, no group access, no ownership: none of it exists on such a site.
		$kit   = s3UploadService($this, ['authOff' => true, 'user' => null, 'canUpdate' => false, 'owns' => false, 'responses' => [
			new HttpResponse(200, '', ['content-length' => '2048', 'content-type' => 'application/pdf', 'etag' => '"abc"']),
		]]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		expect($kit->service->commit($token, null)['item']['uploadedBy'])->toBe('');
	});

	test('a property that is not stored as json gets no upload link, and the message says what to change', function (): void {
		// A property switched to s3file in the schema editor keeps the data type it had.
		$kit = s3UploadService($this, ['properties' => ['brochure' => ['type' => 'string', 'field' => 's3file']]]);

		try {
			$kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf');
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(400)
				->and($e->errorCode)->toBe('s3.bad_type')
				->and($e->getMessage())->toBe('brochure cannot hold an S3 file yet: its Type of Data in the schema is not json. Set it to json, save the schema, and try again.')
				->and($kit->http->requests)->toBe([]);
		}
	});

	test('a folder property puts the key in that folder', function (): void {
		$result = s3UploadService($this)->service->presign('blog', 'post-1', 'shared', 'a.pdf', 10, 'application/pdf');

		expect($result['key'])->toMatch('#^site/downloads/[0-9a-f]{8}-a\.pdf$#');
	});
});

describe('UploadService::commit', function (): void {
	$head = fn (int $size = 2048, string $mime = 'application/pdf'): HttpResponse => new HttpResponse(200, '', [
		'content-length' => (string)$size, 'content-type' => $mime, 'etag' => '"abc"', 'last-modified' => 'x',
	]);

	test('a bad token is refused', function (string $token): void {
		try {
			s3UploadService($this)->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(400)->and($e->errorCode)->toBe('s3.token');
		}
	})->with(['', 'not-a-token', 'aaaa.bbbb']);

	test('a token minted for another user is refused', function (): void {
		$kit   = s3UploadService($this);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		$other = s3UploadService($this, ['user' => 'mallory', 'siteKey' => $kit->siteKey]);

		expect(fn () => $other->service->commit($token, null))->toThrow(UploadError::class, 'This upload expired. Try again.')
			->and($other->http->requests)->toBe([]);
	});

	test('a key that never reached the bucket is a 409', function (): void {
		$kit   = s3UploadService($this, ['responses' => [new HttpResponse(404, '')]]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		try {
			$kit->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(409)->and($e->errorCode)->toBe('s3.missing_upload')
				->and($e->getMessage())->toStartWith('The file did not reach the bucket.')
				->and($kit->patched)->toBe([]);
		}
	});

	test('a file that differs from what was announced is deleted and refused', function (HttpResponse $found) : void {
		$kit   = s3UploadService($this, ['responses' => [$found, new HttpResponse(204, '')]]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		try {
			$kit->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(422)->and($e->errorCode)->toBe('s3.mismatch')
				->and($kit->http->methods())->toBe(['HEAD', 'DELETE'])
				->and($kit->patched)->toBe([]);
		}
	})->with([
		'another size' => [fn () => new HttpResponse(200, '', ['content-length' => '4096', 'content-type' => 'application/pdf'])],
		'another type' => [fn () => new HttpResponse(200, '', ['content-length' => '2048', 'content-type' => 'text/html'])],
	]);

	test('success writes a pointer built from what the bucket reports', function () use ($head): void {
		$kit    = s3UploadService($this, ['responses' => [$head()]]);
		$upload = $kit->service->presign('blog', 'post-1', 'brochure', "Brochure\x00 2026.pdf", 2048, 'application/pdf');
		$result = $kit->service->commit($upload['token'], 12.5);

		$pointer = [
			'id'         => substr(basename($upload['key']), 0, 8),
			'key'        => $upload['key'],
			'name'       => 'Brochure 2026.pdf',
			'size'       => 2048,
			'mime'       => 'application/pdf',
			'etag'       => 'abc',
			'uploadedAt' => '2026-09-05T14:02:11+00:00',
			'uploadedBy' => 'joe',
		];

		expect($result)->toBe(['value' => $pointer, 'item' => $pointer])
			->and($kit->patched)->toBe([['blog', 'post-1', ['brochure' => $pointer]]])
			->and($kit->http->methods())->toBe(['HEAD']);
	});

	test('the type comparison ignores case and surrounding space', function () use ($head): void {
		$kit   = s3UploadService($this, ['responses' => [$head(2048, ' Application/PDF ')]]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		expect($kit->service->commit($token, null)['item']['mime'])->toBe('application/pdf');
	});

	test('duration is kept for audio and video, within a day', function (string $mime, ?float $given, ?float $kept) use ($head): void {
		$kit   = s3UploadService($this, ['responses' => [$head(2048, $mime)]]);
		$token = $kit->service->presign('blog', 'post-1', 'files', 'a.bin', 2048, $mime)['token'];
		$item  = $kit->service->commit($token, $given)['item'];

		expect($item['duration'] ?? null)->toBe($kept);
	})->with([
		'audio'            => ['audio/mpeg', 2714.3, 2714.3],
		'video'            => ['video/mp4', 61.0, 61.0],
		'a document'       => ['application/pdf', 2714.3, null],
		'negative'         => ['audio/mpeg', -5.0, null],
		'longer than a day' => ['audio/mpeg', 90000.0, null],
		'none'             => ['audio/mpeg', null, null],
	]);

	test('a depot commit appends to the current list', function () use ($head): void {
		$existing = ['id' => '0a1b2c3d', 'key' => 'site/blog/post-1/files/0a1b2c3d-old.pdf', 'name' => 'old.pdf'];
		$kit      = s3UploadService($this, ['responses' => [$head()], 'object' => ['files' => [$existing]]]);
		$token    = $kit->service->presign('blog', 'post-1', 'files', 'new.pdf', 2048, 'application/pdf')['token'];
		$result   = $kit->service->commit($token, null);

		expect($result['value'])->toHaveCount(2)
			->and($result['value'][0]['key'])->toBe($existing['key'])
			->and($result['value'][0]['name'])->toBe('old.pdf')
			->and($result['value'][1]['name'])->toBe('new.pdf')
			->and($result['item'])->toBe($result['value'][1]);
	});

	test('a full depot refuses the commit and deletes the key', function () use ($head): void {
		$item = fn (string $id): array => ['id' => $id, 'key' => "site/blog/post-1/gallery/{$id}-x.pdf"];
		$kit  = s3UploadService($this, ['responses' => [$head(), new HttpResponse(204, '')], 'object' => ['gallery' => [$item('0a1b2c3d')]]]);

		$token = $kit->service->presign('blog', 'post-1', 'gallery', 'new.pdf', 2048, 'application/pdf')['token'];
		// A second editor fills the last slot between presign and commit.
		$kit->object->properties['gallery'] = new JsonData([$item('0a1b2c3d'), $item('1a1b2c3d')]);

		try {
			$kit->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(422)->and($e->errorCode)->toBe('s3.count')
				->and($kit->http->methods())->toBe(['HEAD', 'DELETE'])
				->and($kit->patched)->toBe([]);
		}
	});

	test('commit appends under the lock and keeps both items', function () use ($head): void {
		$kit = s3UploadService($this, ['responses' => [$head(), $head()]]);
		$one = $kit->service->presign('blog', 'post-1', 'files', 'one.pdf', 2048, 'application/pdf')['token'];
		$two = $kit->service->presign('blog', 'post-1', 'files', 'two.pdf', 2048, 'application/pdf')['token'];

		// Both uploads finished before either commit; each commit must read the
		// list as the other left it, not the list from when the form loaded.
		$kit->service->commit($one, null);
		$value = $kit->service->commit($two, null)['value'];

		expect(array_column($value, 'name'))->toBe(['one.pdf', 'two.pdf'])
			->and($kit->lockFiles())->toBe(['blog--post-1.lock']);
	});

	test('an object deleted during the upload removes the key', function () use ($head): void {
		$kit   = s3UploadService($this, ['responses' => [$head(), new HttpResponse(204, '')]]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];
		$kit->gone = true;

		try {
			$kit->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(404)->and($e->errorCode)->toBe('s3.no_object')
				->and($kit->http->methods())->toBe(['HEAD', 'DELETE']);
		}
	});

	test('committing the same token twice answers the same value and changes nothing', function () use ($head): void {
		$kit   = s3UploadService($this, ['responses' => [$head(), $head()]]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		$first  = $kit->service->commit($token, null);
		$second = $kit->service->commit($token, null);

		expect($second)->toBe($first)
			->and($kit->patched)->toHaveCount(1)
			->and($kit->http->methods())->not->toContain('DELETE');
	});

	test('a replayed commit on a full depot never deletes the file it already stored', function () use ($head): void {
		$existing = ['id' => '0a1b2c3d', 'key' => 'site/blog/post-1/gallery/0a1b2c3d-x.pdf'];
		$kit      = s3UploadService($this, ['responses' => [$head(), $head(), new HttpResponse(204, '')], 'object' => ['gallery' => [$existing]]]);
		$token    = $kit->service->presign('blog', 'post-1', 'gallery', 'new.pdf', 2048, 'application/pdf')['token'];

		$first  = $kit->service->commit($token, null);
		$second = $kit->service->commit($token, null);

		expect($first['value'])->toHaveCount(2)
			->and($second['value'])->toHaveCount(2)
			->and($second['item']['key'])->toBe($first['item']['key'])
			->and($kit->patched)->toHaveCount(1)
			->and($kit->http->methods())->not->toContain('DELETE');
	});

	test('a save the object refuses removes the upload and says why', function (Throwable $refusal, int $status, string $code, string $message) use ($head): void {
		$kit   = s3UploadService($this, ['responses' => [$head(), new HttpResponse(204, '')], 'patchThrows' => $refusal]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		try {
			$kit->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe($status)->and($e->errorCode)->toBe($code)->and($e->getMessage())->toBe($message)
				->and($kit->http->methods())->toBe(['HEAD', 'DELETE']);
		}
	})->with([
		'validation fails on another field' => [
			fn () => new UnexpectedValueException('Failed schema validation: title is required'),
			422, 's3.save_failed', 'The file uploaded, but the object could not be saved, so the upload was removed. Failed schema validation: title is required',
		],
		'the write gate says no' => [
			fn () => new TotalCMS\Domain\Auth\Exception\AccessDeniedException('You may not update object "post-1" in "blog".'),
			403, 's3.forbidden', 'You do not have permission to upload files to blog.',
		],
	]);

	test('a save that keeps the old value is a refusal, and the upload is removed', function () use ($head): void {
		$kit   = s3UploadService($this, ['responses' => [$head(), new HttpResponse(204, '')], 'patchIgnores' => true]);
		$token = $kit->service->presign('blog', 'post-1', 'brochure', 'a.pdf', 2048, 'application/pdf')['token'];

		try {
			$kit->service->commit($token, null);
			$this->fail('Expected an UploadError.');
		} catch (UploadError $e) {
			expect($e->status)->toBe(403)->and($e->errorCode)->toBe('s3.forbidden')
				->and($kit->http->methods())->toBe(['HEAD', 'DELETE']);
		}
	});
});
