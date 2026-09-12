<?php

declare(strict_types=1);
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/**
 * The table format renders every cell through table-cell.twig. Supported
 * field types carry the inline-edit trigger; identity columns do not.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'hello',
		'title'   => 'Hello World',
		'date'    => '2026-08-01T12:00:00+00:00',
		'created' => '2026-08-01T12:00:00+00:00',
		'updated' => '2026-08-01T12:00:00+00:00',
	]);
});

it('renders cell values and an inline-edit trigger on supported columns', function (): void {
	$response = get('/api/collections/blog/query?format=table&_collection=blog&limit=5');

	$response->assertOk();
	$html = (string)$response->getBody();

	expect($html)->toContain('Hello World');
	expect($html)->toContain('hx-get="collections/blog/hello/cell/title/edit"');
	expect($html)->toContain('class="inline-edit-trigger"');
	// The id column is identity, never inline-editable.
	expect($html)->not->toContain('cell/id/edit');
	expect($html)->not->toContain('cell/updated/edit'); // readonly system timestamp
});

it('renders no inline-edit trigger when the master switch is off', function (): void {
	$config                     = $this->app->getContainer()->get(Config::class);
	$dashboard                  = $config->dashboard;
	$dashboard['inlineEditing'] = false;
	$config->dashboard          = $dashboard;

	$response = get('/api/collections/blog/query?format=table&_collection=blog&limit=5');

	$response->assertOk();
	$html = (string)$response->getBody();

	// The cells still render — only the pencil is gone.
	expect($html)->toContain('Hello World');
	expect($html)->not->toContain('class="inline-edit-trigger"');
	expect($html)->not->toContain('/cell/title/edit');
});
