<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

use function DI\autowire;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

// The admin ImageWorks tool shows a strip of filter tiles instead of a select.
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$this->config           = $c->get(Config::class);
	$this->imageworksBefore = $this->config->imageworks;
	$this->config->imageworks['filters']['site-look'] = ['label' => 'Site Look', 'steps' => [['sepia', 0.5]]];
	$c->set(ImageFilterRegistry::class, autowire());

	$session = $c->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin');
	$session->set(SessionKeys::AUTH_COLLECTION, 'auth');

	$c->get(SchemaSaver::class)->saveSchema(['id' => 'snaps', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'snaps', 'name' => 'Snaps', 'schema' => 'snaps']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's1', 'title' => 'Snap']);
	expect(postUpload('/api/collections/snaps/s1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200);
});

afterEach(function (): void {
	$this->config->imageworks = $this->imageworksBefore;
});

test('the filter strip lists core, legacy and config filters as tiles', function (): void {
	$response = get('/admin/imageworks?collection=snaps&id=s1&property=photo');
	expect($response->getStatusCode())->toBe(200);
	$body = (string) $response->getBody();

	expect($body)
		->toContain('data-filter="ember"')
		->toContain('data-filter="greyscale"')
		->toContain('data-filter="duotone"')
		->toContain('data-filter="site-look"')
		->toContain('Site Look')
		->toContain('Ember')
		->toContain('Black &amp; White')
		->toContain('aria-pressed="false"')
		->toContain('<input type="hidden" name="filt"')
		->toContain('name="filtamt"')
		->toContain('name="duo"');
	expect($body)->not->toMatch('/<select[^>]*name="filt"/');
});

test('filters have their own accordion, after sizing and before effects', function (): void {
	$body = (string) get('/admin/imageworks?collection=snaps&id=s1&property=photo')->getBody();

	$sizing  = strpos($body, '<summary>Sizing');
	$filters = strpos($body, '<summary>Filters</summary>');
	$effects = strpos($body, '<summary>Effects</summary>');
	expect($sizing)->toBeInt();
	expect($filters)->toBeInt()->toBeGreaterThan($sizing);
	expect($effects)->toBeInt()->toBeGreaterThan($filters);
	expect(strpos($body, 'id="filter-strip"'))->toBeGreaterThan($filters)->toBeLessThan($effects);
});

test('duotone colors are a text field with suggestions and an options panel, not color pickers', function (): void {
	$body = (string) get('/admin/imageworks?collection=snaps&id=s1&property=photo')->getBody();

	expect($body)
		->toMatch('/<input[^>]*type="text"[^>]*name="duo"[^>]*list="duo-options"/s')
		->toContain('placeholder="000000,palette0"')
		->toContain('<datalist id="duo-options">')
		->toContain('<option value="palette4,palette0">')
		->toContain('class="open-duo-docs"')
		->toContain('id="duo-modal"');
	expect($body)->not->toContain('type="color"');
});

test('the filters accordion stays closed when the image has no filter', function (): void {
	$body = (string) get('/admin/imageworks?collection=snaps&id=s1&property=photo')->getBody();
	expect($body)->not->toMatch('/<details class="cms-accordion"[^>]*open[^>]*>\s*<summary>Filters/');
});

test('the filters accordion opens on load when the image already has a filter', function (): void {
	// The template reads the query string through the `getData` global ($_GET),
	// which the test request helper does not fill in
	$getBefore = $_GET;
	$_GET      = ['collection' => 'snaps', 'id' => 's1', 'property' => 'photo', 'filt' => 'duotone'];

	try {
		$body = (string) get('/admin/imageworks?collection=snaps&id=s1&property=photo&filt=duotone')->getBody();
	} finally {
		$_GET = $getBefore;
	}

	expect($body)
		->toMatch('/<details class="cms-accordion"[^>]*open[^>]*>\s*<summary>Filters/')
		->toContain('data-filter="duotone" data-group="duotone" aria-pressed="true"')
		->not->toMatch('/<section class="filter-duo"[^>]*hidden/')
		->not->toMatch('/<input[^>]*name="duo"[^>]*disabled/s');
});

test('a chain the page opens with presses every tile in it', function (): void {
	$getBefore = $_GET;
	$_GET      = ['collection' => 'snaps', 'id' => 's1', 'property' => 'photo', 'filt' => 'ember,vignette'];

	try {
		$body = (string) get('/admin/imageworks?collection=snaps&id=s1&property=photo&filt=ember,vignette')->getBody();
	} finally {
		$_GET = $getBefore;
	}

	expect($body)
		->toContain('<input type="hidden" name="filt" value="ember,vignette">')
		->toMatch('/data-filter="ember" data-group="look" aria-pressed="true"/')
		->toMatch('/data-filter="vignette" data-group="effect" aria-pressed="true"/')
		->toMatch('/data-filter="noir" data-group="look" aria-pressed="false"/');
});

test('looks and duotones share one row of tiles, and extras sit in a row of their own', function (): void {
	$body = (string) get('/admin/imageworks?collection=snaps&id=s1&property=photo')->getBody();

	preg_match_all('/<div class="filter-tiles[^"]*"[^>]*>(.*?)<\/div>/s', $body, $rows);
	expect($rows[1])->toHaveCount(2);
	[$main, $extras] = $rows[1];

	expect($main)
		->toContain('data-filter="ember"')
		->toContain('data-filter="greyscale"')
		->toContain('data-filter="lagoon"')
		->toContain('data-filter="duotone"')
		->not->toContain('data-filter="vignette"');
	expect($extras)
		->toContain('data-filter="vignette"')
		->toContain('data-filter="grain" data-group="effect"')
		->toContain('data-filter="warm" data-group="effect"')
		->toContain('data-filter="cool" data-group="effect"')
		->toContain('data-filter="leak" data-group="effect"')
		->toContain('Light Leak')
		->not->toContain('data-filter="ember"');
	expect($main)->not->toContain('data-filter="grain"')->not->toContain('data-filter="leak"');
	// The extras keep the registry order, vignette first
	$order = array_map(static fn (string $name): int|false => strpos($extras, "data-filter=\"{$name}\""), ['vignette', 'grain', 'warm', 'cool', 'leak']);
	$sorted = $order;
	sort($sorted);
	expect($order)->toBe($sorted);
	// Looks come before duotones in the shared row
	expect(strpos($main, 'data-filter="noir"'))->toBeLessThan(strpos($main, 'data-filter="lagoon"'));
	expect($body)->toContain('<h5 class="filter-group-title">Extras</h5>');
});

