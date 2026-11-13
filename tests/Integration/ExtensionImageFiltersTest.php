<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

// Extensions registering ImageWorks filters, gated by the `imageworks:filters`
// capability. Loads the real test-vendor/image-filters fixture.
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();
});

/** Boot the image-filters fixture on the real container, with its capability on or off. */
function bootImageFiltersFixture(ContainerInterface $container, bool $capabilityOn): ExtensionManager
{
	$permissions = ['imageworks:filters' => $capabilityOn, 'routes:api' => true];

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(true);
	$storage->method('read')->willReturn(json_encode([
		'test-vendor/image-filters' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null, 'permissions' => $permissions],
	]));
	$storage->method('write')->willReturn(true);

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = dirname(__DIR__) . '/fixtures';

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);

	$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$manager   = new ExtensionManager(
		new ExtensionDiscovery($config, $validator, new NullLogger(), bundledExtensionsDir: $config->datadir . '/no-bundled-here'),
		new ExtensionStateRepository($storage),
		new ExtensionDependencySorter(),
		new ExtensionSettingsManager($settingsStorage),
		$container,
		new NullLogger(),
		$validator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);
	$manager->discoverAndRegister();
	$manager->bootAll();

	return $manager;
}

test('an enabled extension registers its recipe and class filters', function (): void {
	bootImageFiltersFixture($this->container, true);
	$registry = $this->container->get(ImageFilterRegistry::class);

	expect($registry->get('test-vendor-warm')?->owner)->toBe('test-vendor/image-filters')
		->and($registry->get('test-vendor-grain')?->owner)->toBe('test-vendor/image-filters')
		->and($registry->get('test-vendor-grain')?->pixelLimited)->toBeFalse();
});

test('an unprefixed name is refused while the extensions other filters still register', function (): void {
	bootImageFiltersFixture($this->container, true);
	$registry = $this->container->get(ImageFilterRegistry::class);

	// `warm` is a built-in since the Extras landed: the extension must not take it
	expect($registry->get('warm')?->owner)->toBe('core')
		->and($registry->get('test-vendor-warm'))->not->toBeNull();
});

test('turning the capability off registers neither filter', function (): void {
	$manager  = bootImageFiltersFixture($this->container, false);
	$registry = $this->container->get(ImageFilterRegistry::class);

	expect($manager->getAllImageFilters())->toBe([])
		->and($registry->get('test-vendor-warm'))->toBeNull()
		->and($registry->get('test-vendor-grain'))->toBeNull();
});

test('a filter whose capability is off is served as the unfiltered image', function (): void {
	$c = $this->container;
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'snaps', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
	], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'snaps', 'name' => 'Snaps', 'schema' => 'snaps']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's1']);
	expect(postUpload('/api/collections/snaps/s1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200);

	bootImageFiltersFixture($c, false);

	expect(get('/imageworks/snaps/s1/photo.jpg?w=300&filt=test-vendor-warm')->getStatusCode())->toBe(200);
	expect($c->get(ImageGenerator::class)->cacheState('snaps', 's1', 'photo', null, ['w' => 300, 'fm' => 'jpg']))->toBe(CacheState::Cached);
});
