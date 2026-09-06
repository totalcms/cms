<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/**
 * htmx swaps error responses like any other, so an API error reaching an
 * htmx element must be an HTML fragment, not a JSON body. The fragment keeps
 * the status and message, and a validation failure lists its fields so a
 * form can show inline errors.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');
});

it('renders a 404 as an html fragment for an htmx request', function (): void {
	$response = get('/api/collections/blog/nope', ['HX-Request' => 'true']);

	$response->assertNotFound()->assertHeader('Content-Type', 'text/html');
	expect((string)$response->getBody())->toContain('class="cms-error cms-error-404"')->toContain('role="alert"');
});

it('keeps json for the same error without the header', function (): void {
	$response = get('/api/collections/blog/nope');

	$response->assertNotFound();
	expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
	expect((string)$response->getBody())->toContain('"error"');
});

it('lists the failing fields for a validation error', function (): void {
	// The image property schema types `exif` as an object; a list fails
	// validation at `/image/exif`, which is the shape a form error takes.
	$response = postJson('/api/collections/blog', [
		'id'      => 'bad',
		'title'   => 'Bad',
		'image'   => ['name' => 'x.jpg', 'alt' => '', 'exif' => [1, 2], 'featured' => false, 'focalpoint' => ['x' => 50, 'y' => 50], 'link' => '', 'tags' => []],
		'created' => '2026-08-01T12:00:00+00:00',
		'updated' => '2026-08-01T12:00:00+00:00',
	], ['HX-Request' => 'true']);

	$response->assertBadRequest()->assertHeader('Content-Type', 'text/html');
	expect((string)$response->getBody())
		->toContain('cms-error-fields')
		->toContain('data-field="image.exif"');
});

it('escapes the message so an error cannot inject markup', function (): void {
	$response = get('/api/collections/blog/%3Cscript%3E', ['HX-Request' => 'true']);

	expect((string)$response->getBody())->not->toContain('<script>');
});
