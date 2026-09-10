<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Migration\Repository\MigrationStateRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Middleware\MigrationMiddleware;

use function TotalCMS\Slim\Pest\get;

/**
 * Migrations must run for a Site Builder page request, not just an admin one.
 *
 * MigrationMiddleware used to be registered before addRoutingMiddleware(). A
 * builder page has no Slim route, so RoutingMiddleware threw a 404 that unwound
 * outward to PageRouterMiddleware before the migration middleware was ever
 * reached — a site whose visitors only ever hit builder pages stayed
 * un-migrated until an operator happened to open /admin.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();

	// The middleware fires once per PHP process; another test in this worker may
	// already have tripped the flag.
	(new ReflectionClass(MigrationMiddleware::class))->setStaticPropertyValue('ran', false);

	$this->c->get(BuilderInstaller::class)->ensurePagesCollection();

	// A template for the page to render, so the request is a real builder-page
	// hit rather than a 404 that happens to travel the same middleware path.
	@mkdir(cmsDataDir() . 'builder/pages', 0755, true);
	file_put_contents(templatePath('about', 'pages'), '<h1>{{ page.title }}</h1>');

	// A pre-3.5.3 page written straight to disk: the saver would run it through
	// the migrated schema and drop the very key the migration exists to move.
	file_put_contents(objectPath('builder-pages', 'about'), json_encode([
		'id'          => 'about',
		'title'       => 'About Us',
		'route'       => '/about',
		'template'    => 'pages/about.twig',
		'description' => 'Legacy meta description',
		'draft'       => false,
		'nav'         => true,
		'updated'     => '2026-01-01T00:00:00+00:00',
		'created'     => '2026-01-01T00:00:00+00:00',
	], JSON_PRETTY_PRINT));

	$this->c->get(IndexBuilder::class)->buildIndex('builder-pages');
});

test('a Site Builder page request runs pending migrations', function (): void {
	$state = $this->c->get(MigrationStateRepository::class);
	expect($state->hasRun('builder-page-seo-fields'))->toBeFalse();

	get('/about');

	expect($state->hasRun('builder-page-seo-fields'))->toBeTrue();

	// And it did the work, not just recorded itself.
	$page = $this->c->get(ObjectFetcher::class)->fetchObject('builder-pages', 'about')->toArray();
	expect($page['seo']['description'] ?? '')->toBe('Legacy meta description');
});

test('MigrationMiddleware is registered outside the routing layer', function (): void {
	// Belt and braces for the ordering itself: anything added before
	// addRoutingMiddleware() is skipped entirely when routing 404s, which is
	// every Site Builder page request.
	$source  = (string)file_get_contents(__DIR__ . '/../../config/middleware.php');
	$routing = strpos($source, '$app->addRoutingMiddleware()');
	$added   = strpos($source, '$app->add(MigrationMiddleware::class)');

	expect($routing)->not->toBeFalse()
		->and($added)->not->toBeFalse()
		->and($added)->toBeGreaterThan((int)$routing)
		->and($added)->toBeGreaterThan((int)strpos($source, '$app->add(PageRouterMiddleware::class)'));
});
