<?php

declare(strict_types=1);

use TotalCMS\Renderer\ActiveContentHeaders;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/**
 * GHSA-f354-xm96-5p3w: an `.html` an editor uploaded was served inline as
 * `text/html` from `/imageworks/upload/…` and `/stream/upload/…`, with no
 * CSP — its script ran on the CMS origin. ImageWorks now serves images
 * only, and every stored file carries `nosniff`, with a sandbox CSP on the
 * types a browser runs script in.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	@mkdir(cmsDataDir() . 'x/y/z', 0777, true);
	file_put_contents(cmsDataDir() . 'x/y/z/research.html', '<!doctype html><script>document.body.dataset.ran="yes"</script>');
	file_put_contents(cmsDataDir() . 'x/y/z/drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>');
	copy(testData('test-image.jpg'), cmsDataDir() . 'x/y/z/photo.jpg');
});

it('does not serve HTML through ImageWorks', function (): void {
	$response = get('/imageworks/upload/x/y/z/research.html');

	expect($response->getStatusCode())->toBe(404)
		->and((string)$response->getBody())->not->toContain('<script>');
});

it('still serves an image through ImageWorks', function (): void {
	$response = get('/imageworks/upload/x/y/z/photo.jpg');

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('Content-Type'))->toBe('image/jpeg')
		->and($response->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
		->and($response->getHeaderLine('Content-Security-Policy'))->toBe('');
});

it('sandboxes an SVG served by ImageWorks', function (): void {
	$response = get('/imageworks/upload/x/y/z/drawing.svg');

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('Content-Security-Policy'))->toBe(ActiveContentHeaders::SANDBOX_POLICY);
});

it('sandboxes HTML streamed inline', function (string $file): void {
	$response = get('/stream/upload/x/y/z/' . $file);

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
		->and($response->getHeaderLine('Content-Security-Policy'))->toStartWith('sandbox');
})->with(['research.html', 'drawing.svg']);

it('sends HTML from the download route as a sandboxed attachment', function (): void {
	$response = get('/download/upload/x/y/z/research.html');

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('Content-Disposition'))->toStartWith('attachment')
		->and($response->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
		->and($response->getHeaderLine('Content-Security-Policy'))->toStartWith('sandbox');
});

it('knows which types run script', function (string $mime, bool $active): void {
	expect(ActiveContentHeaders::isActive($mime))->toBe($active);
})->with([
	['text/html', true],
	['text/html; charset=utf-8', true],
	['TEXT/HTML', true],
	['application/xhtml+xml', true],
	['image/svg+xml', true],
	['application/xml', true],
	['application/rss+xml', true],
	['image/jpeg', false],
	['video/mp4', false],
	['application/pdf', false],
	['text/plain', false],
]);

// The canonical file-field routes go through the same renderer, so an
// HTML or SVG on a file field is covered too, not only the raw upload URLs.
describe('file field routes', function (): void {
	beforeEach(function (): void {
		signInAs($this->app, 'admin-user-test-com', 'auth');
		postJson('/api/collections', ['id' => 'docs', 'schema' => 'file'])->assertOk();
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
		$this->setUpApp(bootstrap());
	});

	it('sandboxes an active file served from a file field', function (string $prefix, string $name, string $mime, string $body): void {
		@mkdir(objectFilesPath('docs', 'item') . '/file', 0777, true);
		file_put_contents(objectFilesPath('docs', 'item') . '/file/' . $name, $body);
		file_put_contents(objectPath('docs', 'item'), json_encode([
			'id'   => 'item',
			'file' => ['name' => $name, 'mime' => $mime, 'size' => strlen($body), 'ext' => pathinfo($name, PATHINFO_EXTENSION), 'protected' => false],
		]));

		$response = get($prefix . 'docs/item/file');

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
			->and($response->getHeaderLine('Content-Security-Policy'))->toBe(ActiveContentHeaders::SANDBOX_POLICY);
	})->with(['/download/', '/stream/'])->with([
		'html' => ['page.html', 'text/html', '<!doctype html><script>alert(1)</script>'],
		'svg'  => ['logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
	]);
});
