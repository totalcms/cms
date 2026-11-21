<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Field\S3Field;
use DI\Container;
use Odan\Session\PhpSession;
use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Slim\Test\TestResponse;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

// An install with authentication switched off (`auth.enable = false`) has no
// users at all, and core lets every request through. The extension's own
// access checks must do the same, or the field is dead on such a site: nobody
// is signed in to be allowed anything.

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
			'brochure' => ['type' => 'json', 'field' => 's3file', 'help' => 'The brochure'],
		],
		'required' => ['id'],
		'index'    => ['id', 'name'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'docs', 'name' => 'Docs', 'schema' => 'docs', 'groups' => ['members']]);
	$container->get(ObjectSaver::class)->saveObject('docs', ['id' => 'doc-one', 'name' => 'First']);

	// The field sends the CSRF token from the page with every POST; signing in is not what mints it.
	$session = $container->get(PhpSession::class);
	$session->isStarted() || $session->start();
	withHeader('X-CSRF-Token', $container->get(CSRFTokenManager::class)->getToken());

	/** Upload one file the way the field does; returns the commit response. */
	$this->upload = function (): TestResponse {
		$presign = postJson('/admin/ext/totalcms/s3-uploads/presign', [
			'collection' => 'docs', 'id' => 'doc-one', 'property' => 'brochure', 'filename' => 'a.pdf', 'size' => 2048, 'mime' => 'application/pdf',
		]);
		expect($presign->getStatusCode())->toBe(200);

		$this->bucket->push(new HttpResponse(200, '', ['content-length' => '2048', 'content-type' => 'application/pdf', 'etag' => '"abc"']));

		return postJson('/admin/ext/totalcms/s3-uploads/commit', ['token' => json_decode((string)$presign->getBody(), true)['token']]);
	};
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

test('the site in this test has authentication switched off', function (): void {
	expect($this->app->getContainer()->get(Config::class)->authEnabled())->toBeFalse();
});

test('a file uploads with nobody signed in', function (): void {
	$commit = ($this->upload)();

	expect($commit->getStatusCode())->toBe(200);

	$stored = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObjectFromDisk('docs', 'doc-one')->toArray()['brochure'];
	expect($stored['key'])->toMatch('#^site/docs/doc-one/brochure/[0-9a-f]{8}-a\.pdf$#')
		->and($stored['uploadedBy'])->toBe('');
});

test('the field can read its download counts and open the file', function (): void {
	($this->upload)();

	$stats = get('/admin/ext/totalcms/s3-uploads/stats?collection=docs&id=doc-one');
	$open  = get('/admin/ext/totalcms/s3-uploads/open?collection=docs&id=doc-one&property=brochure');

	expect($stats->getStatusCode())->toBe(200)
		->and((string)$stats->getBody())->toBe('{"counts":{},"totals":{}}')
		->and($open->getStatusCode())->toBe(302)
		->and($open->getHeaderLine('Location'))->toContain('X-Amz-Expires=300');
});

test('a protected file is served: there is no login to send the visitor to', function (): void {
	($this->upload)();

	$response = get('/api/ext/totalcms/s3-uploads/stream/docs/doc-one/brochure');

	expect($response->getStatusCode())->toBe(302)
		->and($response->getHeaderLine('Location'))->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/site/docs/doc-one/brochure/');
});
