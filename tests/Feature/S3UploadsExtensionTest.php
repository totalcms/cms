<?php

declare(strict_types=1);

use Tests\Fakes\OfflineHttpClient;
use TotalCMS\Bundled\S3Uploads\Field\S3Field;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/** The S3 Uploads extension's routes through the real app: auth, CSRF, wiring. */
beforeEach(function (): void {
	$this->fieldTypes = snapshotExtensionFieldTypes();
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	is_dir(cmsDataDir() . '.system') || mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/s3-uploads' => ['enabled' => true]]));
	$this->setUpApp(bootstrap());
	OfflineHttpClient::$attempted = [];
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

$upload = ['collection' => 'blog', 'id' => 'post-1', 'property' => 'brochure', 'filename' => 'a.pdf', 'size' => 2048, 'mime' => 'application/pdf'];

test('a signed-out visitor cannot ask for an upload link', function () use ($upload): void {
	$response = postJson('/admin/ext/totalcms/s3-uploads/presign', $upload);

	expect($response->getStatusCode())->toBeIn([302, 401, 403])
		->and(OfflineHttpClient::$attempted)->toBe([]);
});

test('a signed-in editor reaches the route, which reports that the bucket is not set up', function () use ($upload): void {
	signInAs($this->app, 'admin-user-test-com');

	$response = postJson('/admin/ext/totalcms/s3-uploads/presign', $upload);

	expect($response->getStatusCode())->toBe(503)
		->and(json_decode((string)$response->getBody(), true))->toBe([
			'error' => 'S3 Uploads is not set up yet. A super admin can add the bucket in Extensions → S3 Uploads.',
			'code'  => 's3.unconfigured',
		])
		->and(OfflineHttpClient::$attempted)->toBe([]);
});

test('commit is wired too', function (): void {
	signInAs($this->app, 'admin-user-test-com');

	$response = postJson('/admin/ext/totalcms/s3-uploads/commit', ['token' => 'not-a-token']);

	expect($response->getStatusCode())->toBe(400)
		->and(json_decode((string)$response->getBody(), true)['code'] ?? null)->toBe('s3.token');
});

test('the Test Connection page renders and prints the CORS rules for this site', function (): void {
	signInAs($this->app, 'admin-user-test-com');

	$response = get('/admin/ext/totalcms/s3-uploads/test');
	$body     = (string)$response->getBody();

	expect($response->getStatusCode())->toBe(200)
		->and($body)->toContain('Bucket CORS rules')
		->and($body)->toContain('S3 Uploads is not set up yet.')
		->and($body)->toContain('AllowedOrigins')
		->and($body)->toContain('CORS cannot be tested from the server')
		->and(OfflineHttpClient::$attempted)->toBe([]);
});

test('with a bucket but no prefix, Bucket Setup warns that its policies cover the whole bucket', function (): void {
	$dir = cmsDataDir() . '.system/extension-settings/totalcms';
	is_dir($dir) || mkdir($dir, 0755, true);
	file_put_contents($dir . '/s3-uploads.json', json_encode(['region' => 'us-west-2', 'bucket' => 'shared-bucket', 'accessKey' => 'AKIDEXAMPLE', 'secretKey' => 'secret']));
	$this->setUpApp(bootstrap());
	signInAs($this->app, 'admin-user-test-com');

	$body = (string)get('/admin/ext/totalcms/s3-uploads/test')->getBody();

	expect($body)->toContain('arn:aws:s3:::shared-bucket/*')
		->and($body)->toContain('No prefix is set, so this policy makes every file in <strong>shared-bucket</strong> public')
		// Advice, not a failure: the admin's warning box, never the error style.
		->and($body)->toContain('<div class="warning-box" role="status"><p>No prefix is set. Give each install')
		->and($body)->not->toContain('cms-error')
		->and($body)->not->toContain('s3:prefix');
});

test('for a provider that is not Amazon S3, Bucket Setup offers no Amazon policies', function (): void {
	$dir = cmsDataDir() . '.system/extension-settings/totalcms';
	is_dir($dir) || mkdir($dir, 0755, true);
	file_put_contents($dir . '/s3-uploads.json', json_encode(['endpoint' => 'https://abc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'r2-bucket', 'accessKey' => 'k', 'secretKey' => 's', 'prefix' => 'site/']));
	$this->setUpApp(bootstrap());
	signInAs($this->app, 'admin-user-test-com');

	$body = (string)get('/admin/ext/totalcms/s3-uploads/test')->getBody();

	expect($body)->toContain('Object Read &amp; Write')
		->and($body)->toContain('data-clip="#s3-cors"')
		->and($body)->not->toContain('arn:aws:s3')
		->and($body)->not->toContain('s3-key-policy');
});

test('the counted download route is public and answers 404 for a file that is not there', function (): void {
	$response = get('/api/ext/totalcms/s3-uploads/stream/blog/no-such-post/brochure');

	expect($response->getStatusCode())->toBe(404)
		->and(OfflineHttpClient::$attempted)->toBe([]);
});

test('the stats route answers with an object of counts', function (): void {
	signInAs($this->app, 'admin-user-test-com');

	$response = get('/admin/ext/totalcms/s3-uploads/stats?collection=blog&id=post-1');

	expect($response->getStatusCode())->toBe(200)
		->and((string)$response->getBody())->toBe('{"counts":{},"totals":{}}');
});

test('the preview route answers 404 for a file that is not there', function (): void {
	signInAs($this->app, 'admin-user-test-com');

	expect(get('/admin/ext/totalcms/s3-uploads/open?collection=blog&id=nope&property=brochure')->getStatusCode())->toBe(404);
});

// A capability an already-enabled extension gains later starts switched OFF.
// Sites enable this extension before they add the bucket, so anything it
// registered only once configured (cleanup, the nightly prune) would stay off
// for good. Everything is therefore registered up front.
test('every capability is there before the bucket is configured', function (): void {
	$manager      = $this->app->getContainer()->get(ExtensionManager::class);
	$capabilities = array_keys(array_filter($manager->getCapabilities('totalcms/s3-uploads')));

	expect($capabilities)->toContain('events:listen', 'automations', 'cli:commands', 'fields', 'routes:admin', 'routes:public', 'twig:globals', 'admin:assets');
});
