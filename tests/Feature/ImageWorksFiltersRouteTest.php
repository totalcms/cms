<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\ImageWorks\Service\ImageSourceResolver;
use TotalCMS\Domain\Media\Service\ImagickSupport;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

use function DI\autowire;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

// The `filt` parameter on the public route: validated, cleaned, and keyed
// into the cache so a changed recipe never serves a stale file.
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$this->config           = $c->get(Config::class);
	$this->imageworksBefore = $this->config->imageworks;

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

function filterRouteState(ContainerInterface $container, array $params): CacheState
{
	return $container->get(ImageGenerator::class)->cacheState('snaps', 's1', 'photo', null, $params);
}

/** Whether Glide has a cache file for these literal params, however the route got there. */
function filterRouteGlideHas(ContainerInterface $container, array $params): bool
{
	$image = $container->get(ImageSourceResolver::class)->image('snaps', 's1', 'photo');

	return $container->get(GlideFactory::class)->create(source: 'snaps/s1/photo', imageData: $image)->cacheFileExists($image->name, $params);
}

test('a known filter renders', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=ember')->getStatusCode())->toBe(200);
});

test('an unknown filter shares the unfiltered cache file', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=nosuch')->getStatusCode())->toBe(200);
	expect(filterRouteState($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg']))->toBe(CacheState::Cached);
});

test('an uppercase filter name renders under the lowercase cache key', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=Greyscale')->getStatusCode())->toBe(200);
	expect(filterRouteGlideHas($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg', 'filt' => 'greyscale']))->toBeTrue();
	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=SEPIA')->getStatusCode())->toBe(200);
});

test('changing a recipe makes the cached file stale', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=ember')->getStatusCode())->toBe(200)
		->and(get('/imageworks/snaps/s1/photo.jpg?w=300')->getStatusCode())->toBe(200);
	expect(filterRouteState($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg', 'filt' => 'ember']))->toBe(CacheState::Cached);

	$this->config->imageworks['filters']['ember'] = ['label' => 'Ember', 'steps' => [['sepia', 0.5]]];
	// The registry reads config at construction, so rebuild it and what holds it.
	$c = $this->app->getContainer();
	$c->set(ImageFilterRegistry::class, autowire());
	$c->set(ImageRenderParams::class, autowire());
	$c->set(ImageGenerator::class, autowire());

	expect(filterRouteState($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg', 'filt' => 'ember']))->toBe(CacheState::Missing)
		->and(filterRouteState($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg']))->toBe(CacheState::Cached);
});

test('greyscale has no version in its cache key', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=greyscale')->getStatusCode())->toBe(200);
	// The file sits at the key of the literal params: no filtv is in it
	expect(filterRouteGlideHas($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg', 'filt' => 'greyscale']))->toBeTrue();
});

test('a recipe filter on a too-large GD render is a 400', function (): void {
	$probe = new ReflectionProperty(ImagickSupport::class, 'formats');
	$probe->setValue(null, []);

	try {
		$this->config->imageworks['filterMaxPixels'] = 100;

		expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=ember')->getStatusCode())->toBe(400)
			->and(filterRouteState($this->app->getContainer(), ['w' => 300, 'fm' => 'jpg', 'filt' => 'ember']))->toBe(CacheState::Missing);
	} finally {
		$probe->setValue(null, null);
	}
});

test('an unknown-only filter on the source format keys like no filter', function (): void {
	expect(get('/imageworks/snaps/s1/photo.jpg?filt=nosuch')->getStatusCode())->toBe(200);
	expect(filterRouteState($this->app->getContainer(), ['fm' => 'jpg']))->toBe(CacheState::Cached);
});
