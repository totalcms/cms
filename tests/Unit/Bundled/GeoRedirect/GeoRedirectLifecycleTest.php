<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\GeoRedirect;

use DI\Container;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Bundled\GeoRedirect\GeoRedirectMiddleware;
use TotalCMS\Domain\Builder\PageMiddleware\PageAuthMiddleware;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Smoke test for the geo-redirect bundled extension's discovery + registration.
 *
 * Framework-level checks (entrypoint resolution, container-def wiring,
 * capability gating) are covered by `BundledExtensionLifecycleTest` against
 * ab-split. This test focuses on geo-redirect-specific wire-up: that it
 * shows up in the registry under the right name and resolves to a real
 * GeoRedirectMiddleware instance.
 *
 * Same setup pattern as BundledExtensionLifecycleTest — copy this file as
 * the template for future bundled extensions.
 */
final class GeoRedirectLifecycleTest extends TestCase
{
	private string $tmpRoot;
	private Container $container;
	private ExtensionStateRepository $stateRepo;
	private ExtensionManager $manager;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-geo-lifecycle-' . uniqid();
		mkdir($this->tmpRoot . '/extensions', 0755, true);
		mkdir($this->tmpRoot . '/.system', 0755, true);

		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->tmpRoot;

		$flysystem       = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage         = new StorageFilesystemAdapter($flysystem);
		$this->stateRepo = new ExtensionStateRepository($storage);

		// Autowiring off + minimal definitions — see BundledExtensionLifecycleTest
		// for the rationale. Geo-redirect's middleware has no DI deps so we
		// don't need TwigEngine here.
		$builder = new \DI\ContainerBuilder();
		$builder->useAutowiring(false);
		$builder->useAttributes(false);
		$builder->addDefinitions([
			LoggerFactory::class          => new LoggerFactory([
				'level' => \Monolog\Level::Debug,
				'test'  => new NullLogger(),
			]),
			PageAuthMiddleware::class     => fn (): \PHPUnit\Framework\MockObject\MockObject => $this->createMock(PageAuthMiddleware::class),
			PageMiddlewareRegistry::class => fn (Container $c): PageMiddlewareRegistry => new PageMiddlewareRegistry($c),
		]);
		$this->container = $builder->build();

		$validator     = new ManifestValidator($this->createMock(EditionFeatureService::class));
		$discovery     = new ExtensionDiscovery($config, $validator, new NullLogger());
		$settingsStore = $this->createMock(StorageFilesystemAdapter::class);
		$settingsStore->method('fileExists')->willReturn(false);

		$this->manager = new ExtensionManager(
			$discovery,
			$this->stateRepo,
			new ExtensionDependencySorter(),
			new ExtensionSettingsManager($settingsStore),
			$this->container,
			new NullLogger(),
			$validator,
		);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpRoot);
	}

	public function testGeoRedirectIsDiscoveredAsBundled(): void
	{
		$this->manager->discoverAndRegister();
		$manifests = $this->manager->getDiscoveredManifests();

		$this->assertArrayHasKey('totalcms/geo-redirect', $manifests);
		$this->assertTrue($manifests['totalcms/geo-redirect']->bundled);
	}

	public function testRegistryResolvesGeoRedirect(): void
	{
		// The full chain — discovered, entrypoint loaded, sibling
		// middleware loaded, container def applied, name registered.
		$this->stateRepo->saveState('totalcms/geo-redirect', new ExtensionState(
			enabled: true,
			installedAt: date('c'),
			version: '0.0.0',
		));

		$this->manager->discoverAndRegister();
		$this->manager->bootAll();

		$registry = $this->container->get(PageMiddlewareRegistry::class);
		$this->assertContains('geo-redirect', $registry->availableNames());
		$this->assertInstanceOf(GeoRedirectMiddleware::class, $registry->resolve('geo-redirect'));
	}

	public function testDisabledByDefaultUntilEnabled(): void
	{
		// New install picks up the bundled extension but doesn't auto-enable it.
		$this->manager->discoverAndRegister();
		$this->manager->bootAll();

		$registry = $this->container->get(PageMiddlewareRegistry::class);
		$this->assertNotContains('geo-redirect', $registry->availableNames());
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
