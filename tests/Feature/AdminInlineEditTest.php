<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\patch;

/**
 * The inline-edit round trip on the admin collection table: fetch a cell,
 * fetch its one-field edit form, PATCH the value, get the cell back.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'hello',
		'title'   => 'Hello',
		'summary' => 'A summary',
		'date'    => '2026-08-01T12:00:00+00:00',
		'created' => '2026-08-01T12:00:00+00:00',
		'updated' => '2026-08-01T12:00:00+00:00',
	]);
	signInAs($this->app, 'blogger-user-test-com', 'auth');
});

it('renders the display cell for a property', function (): void {
	$response = get('/admin/collections/blog/hello/cell/title');

	$response->assertOk()->assertHeader('Content-Type', 'text/html');
	expect((string)$response->getBody())->toContain('Hello')->toContain('inline-edit-trigger');
});

it('renders a one-field edit form with the current value', function (): void {
	$response = get('/admin/collections/blog/hello/cell/title/edit');

	$response->assertOk();
	$html = (string)$response->getBody();
	expect($html)->toContain('class="totalform inline-edit no-save no-status-banner no-unsaved-warning"')
		->toContain('data-inline-edit="1"')
		->toContain('data-action="collections/blog/hello/cell/title"')
		->toContain('name="title"')
		->toContain('value="Hello"')
		->toContain('hx-get="collections/blog/hello/cell/title"');
	expect($html)->not->toContain('name="summary"');
	expect($html)->not->toContain('form-group-icon'); // no room for the icon slot in a cell
	expect($html)->not->toContain('class="help"');    // schema help stays on the object form
});

it('saves a patched value and returns the cell with it', function (): void {
	$response = patch('/admin/collections/blog/hello/cell/title', ['data' => json_encode(['title' => 'Renamed'])]);

	$response->assertOk()->assertHeader('Content-Type', 'text/html');
	expect((string)$response->getBody())->toContain('Renamed');

	$object = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray();
	expect($object['title'])->toBe('Renamed');
	expect($object['summary'])->toBe('A summary');
});

it('refuses to edit an identity or readonly field inline', function (): void {
	get('/admin/collections/blog/hello/cell/id/edit')->assertBadRequest();
	get('/admin/collections/blog/hello/cell/updated/edit')->assertBadRequest();
	patch('/admin/collections/blog/hello/cell/updated', ['data' => json_encode(['updated' => '2000-01-01T00:00:00+00:00'])])->assertBadRequest();
});

it('returns 404 for an unknown property or object', function (): void {
	get('/admin/collections/blog/hello/cell/nope/edit')->assertNotFound();
	get('/admin/collections/blog/missing/cell/title')->assertNotFound();
});

it('rejects a patch without data for the property', function (): void {
	patch('/admin/collections/blog/hello/cell/title', ['data' => json_encode(['summary' => 'x'])])->assertBadRequest();
});
