<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Field\S3Field;
use DI\Container;
use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

// The S3 Uploads extension through the real app: a real schema with an s3file
// property, the real save pipeline and event dispatcher, the real routes.
// Only the bucket is a fake, so every request the site would send to it can
// be read back.

beforeEach(function (): void {
	$this->fieldTypes = snapshotExtensionFieldTypes();
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	$system = cmsDataDir() . '.system';
	is_dir($system . '/extension-settings/totalcms') || mkdir($system . '/extension-settings/totalcms', 0755, true);
	file_put_contents($system . '/extensions.json', json_encode(['totalcms/s3-uploads' => ['enabled' => true]]));
	file_put_contents($system . '/extension-settings/totalcms/s3-uploads.json', json_encode([
		'region' => 'us-west-2', 'bucket' => 'my-bucket', 'accessKey' => 'AKIDEXAMPLE', 'secretKey' => 'secret', 'prefix' => 'site/',
	]));

	$this->setUpApp(bootstrap());

	$this->bucket = new RecordingHttpClient();
	$container    = $this->app->getContainer();
	assert($container instanceof Container);
	$container->set(HttpClientInterface::class, $this->bucket);

	$container->get(SchemaSaver::class)->saveSchema([
		'id'          => 'docs',
		'description' => 'Documents',
		'properties'  => [
			'id'       => ['type' => 'string', 'field' => 'id', 'help' => 'Id'],
			'name'     => ['type' => 'string', 'field' => 'text', 'help' => 'Name'],
			'brochure' => ['type' => 'json', 'field' => 's3file', 'help' => 'The brochure', 'settings' => ['rules' => ['filetype' => ['application/pdf']]]],
			'files'    => ['type' => 'json', 'field' => 's3depot', 'help' => 'More files', 'settings' => ['rules' => ['count' => ['max' => 3]]]],
		],
		'required' => ['id'],
		'index'    => ['id', 'name'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'docs', 'name' => 'Docs', 'schema' => 'docs']);
	// No brochure yet: an object with an empty s3file must save.
	$container->get(ObjectSaver::class)->saveObject('docs', ['id' => 'doc-one', 'name' => 'First']);

	signInAs($this->app, 'admin-user-test-com');

	/** Upload one file the way the field does; returns the committed pointer. */
	$this->upload = function (string $name, int $size = 2048, string $property = 'brochure'): array {
		$presign = postJson('/admin/ext/totalcms/s3-uploads/presign', [
			'collection' => 'docs', 'id' => 'doc-one', 'property' => $property, 'filename' => $name, 'size' => $size, 'mime' => 'application/pdf',
		]);
		expect($presign->getStatusCode())->toBe(200);
		$link = json_decode((string)$presign->getBody(), true);

		// The browser's PUT goes straight to the bucket; then the site asks the bucket what arrived.
		$this->bucket->push(new HttpResponse(200, '', ['content-length' => (string)$size, 'content-type' => 'application/pdf', 'etag' => '"abc"']));
		$commit = postJson('/admin/ext/totalcms/s3-uploads/commit', ['token' => $link['token']]);
		expect($commit->getStatusCode())->toBe(200);

		return json_decode((string)$commit->getBody(), true)['value'];
	};

	$this->stored = fn (): mixed => $this->app->getContainer()->get(ObjectFetcher::class)->fetchObjectFromDisk('docs', 'doc-one')->toArray()['brochure'] ?? null;

	/** @return list<string> the keys the site asked the bucket to delete */
	$this->deleted = fn (): array => array_map(
		fn (array $request): string => rawurldecode((string)parse_url($request['url'], PHP_URL_PATH)),
		array_values(array_filter($this->bucket->requests, fn (array $request): bool => $request['method'] === 'DELETE')),
	);
});

// The extension registers its field types, and its fields' "uploads are off"
// notice, in process-wide statics. Put both back, or they leak into whatever
// test file this worker runs next.
afterEach(function (): void {
	restoreExtensionFieldTypes($this->fieldTypes);
	if (class_exists(S3Field::class, false)) {
		S3Field::configure(null);
	}
});

test('an upload ends with the pointer saved on the object', function (): void {
	$pointer = ($this->upload)('Brochure 2026.pdf');

	expect($pointer['key'])->toMatch('#^site/docs/doc-one/brochure/[0-9a-f]{8}-brochure-2026\.pdf$#')
		->and($pointer['name'])->toBe('Brochure 2026.pdf')
		->and($pointer['size'])->toBe(2048)
		->and($pointer['uploadedBy'])->toBe('admin-user-test-com')
		->and(($this->stored)())->toBe($pointer)
		->and($this->bucket->methods())->toBe(['HEAD'])
		->and($this->bucket->requests[0]['url'])->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/' . $pointer['key']);
});

test('the schema\'s rules are enforced before a link is given', function (): void {
	$response = postJson('/admin/ext/totalcms/s3-uploads/presign', [
		'collection' => 'docs', 'id' => 'doc-one', 'property' => 'brochure', 'filename' => 'a.zip', 'size' => 10, 'mime' => 'application/zip',
	]);

	expect($response->getStatusCode())->toBe(422)
		->and(json_decode((string)$response->getBody(), true)['code'])->toBe('s3.filetype')
		->and($this->bucket->requests)->toBe([]);
});

test('replacing the file deletes the old one from the bucket', function (): void {
	$old = ($this->upload)('old.pdf');
	$new = ($this->upload)('new.pdf');

	expect(($this->stored)()['key'])->toBe($new['key'])
		->and(($this->deleted)())->toBe(['/' . $old['key']]);
});

test('saving the object with the same pointer, as the form does, deletes nothing', function (): void {
	$pointer = ($this->upload)('a.pdf');

	$response = putJson('/api/collections/docs/doc-one', ['id' => 'doc-one', 'name' => 'Renamed', 'brochure' => $pointer]);

	expect($response->getStatusCode())->toBeIn([200, 201])
		->and(($this->stored)())->toBe($pointer)
		->and(($this->deleted)())->toBe([]);
});

test('clearing the field on save deletes the file from the bucket', function (): void {
	$pointer  = ($this->upload)('a.pdf');
	$response = putJson('/api/collections/docs/doc-one', ['id' => 'doc-one', 'name' => 'First', 'brochure' => null]);

	expect($response->getStatusCode())->toBeIn([200, 201])
		->and(($this->stored)())->toBeIn(['', null])
		->and(($this->deleted)())->toBe(['/' . $pointer['key']]);
});

test('deleting the object deletes its file from the bucket', function (): void {
	$pointer = ($this->upload)('a.pdf');

	$this->app->getContainer()->get(ObjectRemover::class)->deleteObject('docs', 'doc-one');

	expect(($this->deleted)())->toBe(['/' . $pointer['key']]);
});

test('the counted link redirects to the file and counts the download', function (): void {
	$pointer = ($this->upload)('a.pdf');

	$response = get('/api/ext/totalcms/s3-uploads/stream/docs/doc-one/brochure');
	$stats    = get('/admin/ext/totalcms/s3-uploads/stats?collection=docs&id=doc-one');

	expect($response->getStatusCode())->toBe(302)
		->and($response->getHeaderLine('Location'))->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/' . $pointer['key'])
		->and((string)$stats->getBody())->toBe('{"counts":{"brochure":1},"totals":{"brochure":1}}');
});

test('a download fires file.downloaded, and replacing the file starts its count again', function (): void {
	($this->upload)('first.pdf');

	$fired = [];
	$this->app->getContainer()->get(EventDispatcher::class)->listen(CoreEvent::FILE_DOWNLOADED, function (array $payload) use (&$fired): void {
		$fired[] = [$payload['collection'], $payload['id'], $payload['property'], $payload['filename'], $payload['protected']];
	});

	// Both routes count; only /download reports, as in core.
	get('/api/ext/totalcms/s3-uploads/stream/docs/doc-one/brochure');
	$download = get('/api/ext/totalcms/s3-uploads/download/docs/doc-one/brochure');
	$counts = fn (): string => (string)get('/admin/ext/totalcms/s3-uploads/stats?collection=docs&id=doc-one')->getBody();

	expect($fired)->toBe([['docs', 'doc-one', 'brochure', 'first.pdf', false]])
		->and($download->getHeaderLine('Location'))->toContain('response-content-disposition=attachment')
		// The query string of the first version no longer turns a stream into a download.
		->and(get('/api/ext/totalcms/s3-uploads/dl/docs/doc-one/brochure')->getStatusCode())->toBe(404)
		->and($counts())->toBe('{"counts":{"brochure":2},"totals":{"brochure":2}}');

	($this->upload)('second.pdf');

	// The new file starts at nothing; the brochure's all-time total stays.
	expect($counts())->toBe('{"counts":{},"totals":{"brochure":2}}');
});

test('the Bucket Setup page builds the provider snippets from the saved settings, each with a copy button', function (): void {
	$body = (string)get('/admin/ext/totalcms/s3-uploads/test')->getBody();

	expect($body)->toContain('Bucket Setup')
		->and($body)->toContain('arn:aws:s3:::my-bucket/site/*')
		->and($body)->toContain('&quot;s3:prefix&quot;: &quot;site/*&quot;')
		->and($body)->toContain('&quot;Principal&quot;: &quot;*&quot;')
		->and($body)->toContain('AllowedOrigins')
		->and($body)->toContain('class="dash-button cms-clip-button" data-clip="#s3-key-policy"')
		->and($body)->toContain('data-clip="#s3-cors"')
		->and($body)->toContain('data-clip="#s3-public-policy"')
		->and($this->bucket->requests)->toBe([]);
});

test('a property that names the field and no data type is saved as json', function (): void {
	$container = $this->app->getContainer();
	$container->get(SchemaSaver::class)->saveSchema([
		'id'          => 'untyped',
		'description' => 'Untyped',
		'properties'  => [
			'id'    => ['type' => 'string', 'field' => 'id', 'help' => 'Id'],
			'one'   => ['field' => 's3file', 'help' => 'One file'],
			'blank' => ['type' => '', 'field' => 's3file', 'help' => 'One file, type left blank'],
			'many'  => ['field' => 's3depot', 'help' => 'Many files'],
		],
		'required' => ['id'],
		'index'    => ['id'],
	]);

	$saved = $container->get(TotalCMS\Domain\Schema\Service\SchemaFetcher::class)->fetchSchema('untyped')->toArray()['properties'];

	// On disk a data type is a reference to its property schema.
	$json = 'https://www.totalcms.co/schemas/properties/json.json';

	expect($saved['one']['$ref'])->toBe($json)
		->and($saved['blank']['$ref'])->toBe($json)
		->and($saved['many']['$ref'])->toBe($json);
});

test('a collection whose only group is "default" serves its files to any signed-in member, as core does', function (): void {
	($this->upload)('a.pdf');
	$this->app->getContainer()->get(CollectionSaver::class)->patchCollection('docs', ['groups' => ['default']]);
	$link = '/api/ext/totalcms/s3-uploads/stream/docs/doc-one/brochure';

	// A member who is not a super admin. The property has no protection
	// setting, so the collection's groups apply to it.
	signInAs($this->app, 'blogger-user-test-com', 'auth');
	expect($this->app->getContainer()->get(AccessManager::class)->sessionIsSuperAdmin())->toBeFalse();
	$response = get($link);

	expect($response->getStatusCode())->toBe(302)
		->and($response->getHeaderLine('Location'))->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/site/docs/doc-one/brochure/');
});

test('the preview link is a short signed URL', function (): void {
	$pointer  = ($this->upload)('a.pdf');
	$response = get('/admin/ext/totalcms/s3-uploads/open?collection=docs&id=doc-one&property=brochure');

	expect($response->getStatusCode())->toBe(302)
		->and($response->getHeaderLine('Location'))->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/' . $pointer['key'] . '?')
		->and($response->getHeaderLine('Location'))->toContain('X-Amz-Expires=300');
});

test('the object form renders the field, its file and the extension\'s assets', function (): void {
	($this->upload)('Brochure 2026.pdf');

	$body = (string)get('/admin/collections/docs/doc-one')->getBody();

	expect($body)->toContain('data-type="s3file"')
		->and($body)->toContain('class="s3-field" data-state="filled"')
		->and($body)->toContain('Brochure 2026.pdf')
		->and($body)->toContain('s3-field.js')
		->and($body)->toContain('s3-field.css')
		->and($body)->not->toContain('s3-notice');
});

test('a new object\'s form shows the empty field', function (): void {
	$body = (string)get('/admin/collections/docs/add')->getBody();

	expect($body)->toContain('class="s3-field" data-state="empty"');
});

test('a depot keeps every file, serves each by its id, and deletes only the one that is removed', function (): void {
	($this->upload)('one.pdf', 2048, 'files');
	$files = ($this->upload)('two.pdf', 2048, 'files');

	expect(array_column($files, 'name'))->toBe(['one.pdf', 'two.pdf']);

	$stored = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObjectFromDisk('docs', 'doc-one')->toArray()['files'];
	expect($stored)->toBe($files);

	$response = get('/api/ext/totalcms/s3-uploads/stream/docs/doc-one/files/' . $files[1]['id']);
	expect($response->getStatusCode())->toBe(302)
		->and($response->getHeaderLine('Location'))->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/' . $files[1]['key'])
		->and(get('/api/ext/totalcms/s3-uploads/stream/docs/doc-one/files')->getStatusCode())->toBe(404);

	// The editor removes the first file and saves the form.
	$save = putJson('/api/collections/docs/doc-one', ['id' => 'doc-one', 'name' => 'First', 'brochure' => '', 'files' => [$files[1]]]);

	expect($save->getStatusCode())->toBeIn([200, 201])
		->and(($this->deleted)())->toBe(['/' . $files[0]['key']]);
});

test('a depot refuses a file past its limit', function (): void {
	foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
		($this->upload)($name, 2048, 'files');
	}

	$response = postJson('/admin/ext/totalcms/s3-uploads/presign', [
		'collection' => 'docs', 'id' => 'doc-one', 'property' => 'files', 'filename' => 'd.pdf', 'size' => 2048, 'mime' => 'application/pdf',
	]);

	expect($response->getStatusCode())->toBe(422)
		->and(json_decode((string)$response->getBody(), true)['error'])->toBe('This field holds at most 3 files.');
});

test('the object form renders the depot with a card per file', function (): void {
	($this->upload)('one.pdf', 2048, 'files');

	$body = (string)get('/admin/collections/docs/doc-one')->getBody();

	expect($body)->toContain('data-type="s3depot"')
		->and($body)->toContain('class="s3-field s3-depot" data-state="filled"')
		->and($body)->toContain('one.pdf');
});
