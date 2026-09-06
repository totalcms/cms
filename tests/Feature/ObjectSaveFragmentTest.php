<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\patch;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\put;

/**
 * Object writes stay JSON unless the caller is htmx AND names a template;
 * then the saved object comes back rendered through it. A plain <form
 * hx-post> is a complete contact form with no runtime of its own.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	$templateDir = cmsDataDir() . 'builder/templates/test/';
	if (!is_dir($templateDir)) {
		mkdir($templateDir, 0755, true);
	}
	file_put_contents($templateDir . 'thanks.twig', '<p class="thanks">Saved {{ object.title }} in {{ collection }}</p>');

	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');
});

$fields = ['id' => 'hello', 'title' => 'Hello', 'created' => '2026-08-01T12:00:00+00:00', 'updated' => '2026-08-01T12:00:00+00:00'];

it('renders the template for an htmx form post', function () use ($fields): void {
	$response = post('/api/collections/blog?template=test/thanks', $fields, ['HX-Request' => 'true']);

	$response->assertOk()->assertHeader('Content-Type', 'text/html');
	expect((string)$response->getBody())->toBe('<p class="thanks">Saved Hello in blog</p>');
});

it('returns json when the header is missing, even with a template', function () use ($fields): void {
	$response = postJson('/api/collections/blog?template=test/thanks', $fields);

	$response->assertOk();
	expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
});

it('returns json for an htmx request that names no template', function () use ($fields): void {
	$response = postJson('/api/collections/blog', $fields, ['HX-Request' => 'true']);

	$response->assertOk();
	expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
});

it('renders the template on put and patch as well', function () use ($fields): void {
	postJson('/api/collections/blog', $fields)->assertOk();

	$put = put('/api/collections/blog/hello?template=test/thanks', array_merge($fields, ['title' => 'Updated']), ['HX-Request' => 'true']);
	$put->assertOk();
	expect((string)$put->getBody())->toContain('Saved Updated');

	$patch = patch('/api/collections/blog/hello?template=test/thanks', ['title' => 'Patched'], ['HX-Request' => 'true']);
	$patch->assertOk();
	expect((string)$patch->getBody())->toContain('Saved Patched');
});

it('returns the error fragment when validation fails on an htmx post', function (): void {
	$response = post('/api/collections/blog?template=test/thanks', [
		'id'      => 'bad',
		'title'   => 'Bad',
		'image'   => ['name' => 'x.jpg', 'alt' => '', 'exif' => [1, 2], 'featured' => false, 'focalpoint' => ['x' => 50, 'y' => 50], 'link' => '', 'tags' => []],
		'created' => '2026-08-01T12:00:00+00:00',
		'updated' => '2026-08-01T12:00:00+00:00',
	], ['HX-Request' => 'true']);

	$response->assertBadRequest()->assertHeader('Content-Type', 'text/html');
	expect((string)$response->getBody())->toContain('data-field="image.exif"');
});
