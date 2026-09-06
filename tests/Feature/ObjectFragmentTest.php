<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

/**
 * `GET /api/collections/{c}/{id}?format=html&template=…` renders one object
 * through a builder template — the single-object counterpart of the query
 * endpoint's HTML mode.
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
	file_put_contents($templateDir . 'quick.twig', '<article class="quick" data-collection="{{ collection }}">{{ object.title }}</article>');

	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	$container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$container->get(TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('blog', [
		'id'      => 'hello',
		'title'   => 'Hello World',
		'date'    => '2026-08-01T12:00:00+00:00',
		'created' => '2026-08-01T12:00:00+00:00',
		'updated' => '2026-08-01T12:00:00+00:00',
	]);
});

it('renders one object through the template as html', function (): void {
	get('/api/collections/blog/hello?format=html&template=test/quick')
		->assertOk()
		->assertHeader('Content-Type', 'text/html')
		->assertSee('<article class="quick" data-collection="blog">Hello World</article>');
});

it('still returns json without the format switch', function (): void {
	$response = get('/api/collections/blog/hello');

	$response->assertOk();
	expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
	expect((string)$response->getBody())->toContain('"title"');
});

it('returns 400 when html is requested without a template', function (): void {
	get('/api/collections/blog/hello?format=html')->assertBadRequest();
});

it('returns 404 for a missing object in html mode too', function (): void {
	get('/api/collections/blog/nope?format=html&template=test/quick')->assertNotFound();
});
