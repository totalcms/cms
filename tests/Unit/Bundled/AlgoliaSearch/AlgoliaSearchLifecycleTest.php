<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\AlgoliaSearch;

// Bundled extensions are not in the Composer autoloader — load the service class
// the same way Extension.php does (and as AlgoliaSearchProviderTest.php does).
require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/algolia-search/Service/AlgoliaSearchProvider.php';

use DI\Container;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Bundled\AlgoliaSearch\Service\AlgoliaSearchProvider;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Smoke tests for the Algolia Search bundled extension's discovery +
 * registration lifecycle.
 *
 * Covers:
 *   1. Discovery: the manifest is detected as bundled.
 *   2. Happy path: when edition allows + extension enabled, the provider
 *      lands in SearchProviderRegistry under 'algolia'.
 *   3. Edition-disallowed path: provider is NOT registered when
 *      EditionFeature::ALGOLIA_SEARCH returns false.
 *   4. Disabled-by-default: no state saved → provider not in registry.
 *
 * Uses the same setup pattern as GeoRedirectLifecycleTest. A helper
 * `buildManager(bool $editionAllows)` constructs a fresh container +
 * ExtensionManager per-test so edition-gate tests don't bleed into one
 * another.
 */
final class AlgoliaSearchLifecycleTest extends TestCase
{
	private string $tmpRoot;
	private ExtensionStateRepository $stateRepo;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-algolia-lifecycle-' . uniqid();
		mkdir($this->tmpRoot . '/extensions', 0755, true);
		mkdir($this->tmpRoot . '/.system', 0755, true);

		$flysystem       = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage         = new StorageFilesystemAdapter($flysystem);
		$this->stateRepo = new ExtensionStateRepository($storage);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpRoot);
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function testAlgoliaIsDiscoveredAsBundled(): void
	{
		['manager' => $manager] = $this->buildManager(true);

		$manager->discoverAndRegister();
		$manifests = $manager->getDiscoveredManifests();

		$this->assertArrayHasKey('totalcms/algolia-search', $manifests);
		$this->assertTrue($manifests['totalcms/algolia-search']->bundled);
	}

	public function testRegistryResolvesAlgoliaProviderWhenEditionAllows(): void
	{
		['manager' => $manager, 'container' => $container] = $this->buildManager(true);

		$this->stateRepo->saveState('totalcms/algolia-search', new ExtensionState(
			enabled: true,
			installedAt: date('c'),
			version: '0.0.0',
		));

		$manager->discoverAndRegister();
		$manager->bootAll();

		/** @var SearchProviderRegistry $registry */
		$registry = $container->get(SearchProviderRegistry::class);
		$this->assertInstanceOf(AlgoliaSearchProvider::class, $registry->get('algolia'));
	}

	public function testRegisterIsNoopWhenEditionDisallows(): void
	{
		['manager' => $manager, 'container' => $container] = $this->buildManager(false);

		$this->stateRepo->saveState('totalcms/algolia-search', new ExtensionState(
			enabled: true,
			installedAt: date('c'),
			version: '0.0.0',
		));

		$manager->discoverAndRegister();
		$manager->bootAll();

		/** @var SearchProviderRegistry $registry */
		$registry = $container->get(SearchProviderRegistry::class);
		$this->assertNull($registry->get('algolia'));
	}

	public function testDisabledByDefaultUntilEnabled(): void
	{
		['manager' => $manager, 'container' => $container] = $this->buildManager(true);

		// No ExtensionState saved — fresh install.
		$manager->discoverAndRegister();
		$manager->bootAll();

		/** @var SearchProviderRegistry $registry */
		$registry = $container->get(SearchProviderRegistry::class);
		$this->assertNull($registry->get('algolia'));
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a fresh container + manager for one test.
	 *
	 * @return array{manager: ExtensionManager, container: Container}
	 */
	private function buildManager(bool $editionAllows): array
	{
		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->tmpRoot;

		// EditionFeatureService mock — controls the Pro-edition gate in Extension.php.
		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('can')
			->with(EditionFeature::ALGOLIA_SEARCH)
			->willReturn($editionAllows);

		// SearchProviderRegistry: real instance, pre-seeded into container.
		$registry = new SearchProviderRegistry();

		$builder = new \DI\ContainerBuilder();
		$builder->useAutowiring(false);
		$builder->useAttributes(false);
		$builder->addDefinitions([
			LoggerFactory::class            => new LoggerFactory([
				'level' => \Monolog\Level::Debug,
				'test'  => new NullLogger(),
			]),
			EditionFeatureService::class    => fn () => $editionFeatures,
			SearchProviderRegistry::class   => fn () => $registry,
		]);
		$container = $builder->build();

		$validator = new ManifestValidator($this->createMock(EditionFeatureService::class));

		$flysystem    = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage      = new StorageFilesystemAdapter($flysystem);
		$stateRepo    = new ExtensionStateRepository($storage);

		// Copy the state from the shared stateRepo (setUp creates it pointing to tmpRoot)
		// by giving this manager the same tmpRoot-backed storage.
		$settingsStore = $this->createMock(StorageFilesystemAdapter::class);
		$settingsStore->method('fileExists')->willReturn(false);

		$discovery = new ExtensionDiscovery($config, $validator, new NullLogger());

		$manager = new ExtensionManager(
			$discovery,
			$stateRepo,
			new ExtensionDependencySorter(),
			new ExtensionSettingsManager($settingsStore),
			$container,
			new NullLogger(),
			$validator,
			testExtensionGuard(),
		);

		return ['manager' => $manager, 'container' => $container];
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
