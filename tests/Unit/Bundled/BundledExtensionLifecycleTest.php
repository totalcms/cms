<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled;

use DI\Container;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Bundled\AbSplit\AbSplitMiddleware;
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
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Integration test for the full lifecycle of a bundled extension —
 * discovery, registration, container-definition wiring, page-middleware
 * registry registration, and runtime resolution.
 *
 * This catches the class of bugs that bit us building ab-split:
 *  - addContainerDefinition() being a no-op (factories never reaching DI)
 *  - Sibling classes not being loadable from the entrypoint
 *  - resolveClassName() picking up the wrong "class" token
 *  - capability-state mismatches blocking registration
 *
 * Uses the REAL bundled `totalcms/ab-split` extension as the subject so
 * any change there is exercised end-to-end. Other deps (TwigEngine) are
 * mocked since this test is about the lifecycle, not rendering.
 */
final class BundledExtensionLifecycleTest extends TestCase
{
	private string $tmpRoot;
	private Container $container;
	private ExtensionStateRepository $stateRepo;
	private ExtensionManager $manager;

	protected function setUp(): void
	{
		// User-extensions dir lives under tcms-data — point at an empty tmp
		// dir so discovery sees ONLY the real bundled extension. Bundled path
		// stays at the real package root because we WANT the real ab-split
		// loaded.
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-bundled-lifecycle-' . uniqid();
		mkdir($this->tmpRoot . '/extensions', 0755, true);
		mkdir($this->tmpRoot . '/.system', 0755, true);

		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->tmpRoot;

		// Real state repo backed by Flysystem on the tmp dir.
		$flysystem       = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage         = new StorageFilesystemAdapter($flysystem);
		$this->stateRepo = new ExtensionStateRepository($storage);

		// Container has the bare minimum bootAll's `has()` checks should
		// pass for. PageMiddlewareRegistry is real (the thing we're testing
		// the wire to). TwigEngine is deliberately NOT defined here — its
		// presence triggers bootAll to wire Twig items, which would pull in
		// a chain of Twig classes we don't need for a lifecycle test. We
		// add it back after bootAll for tests that actually instantiate the
		// middleware.
		//
		// Autowiring is OFF: we want $container->has(X) to be deterministic —
		// only true for what we explicitly defined. Otherwise PHP-DI happily
		// claims it `has()` any class with a defaultable constructor and then
		// crashes when the manager actually calls `get()`.
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

	public function testBundledAbSplitIsDiscovered(): void
	{
		$this->manager->discoverAndRegister();
		$manifests = $this->manager->getDiscoveredManifests();

		$this->assertArrayHasKey('totalcms/ab-split', $manifests);
		$this->assertTrue(
			$manifests['totalcms/ab-split']->bundled,
			'Bundled extensions must be flagged so admins can see they cannot be removed',
		);
	}

	public function testEntrypointClassResolvesEvenWithCommentsContainingTheWordClass(): void
	{
		// resolveClassName() once picked up "class" from inside a comment
		// and produced a bogus className. With the tokenizer-based parser
		// this no longer happens — but we exercise it here as a regression
		// guard. ab-split's Extension.php contains the word "classes" in
		// its require_once comment.
		$this->enableExtension('totalcms/ab-split');
		$this->manager->discoverAndRegister();

		// If class resolution failed, the extension would be in the manifests
		// map but NOT in loadedExtensions (registerExtension would have
		// returned early before saving to the contexts/loaded maps).
		$this->assertNotEmpty(
			$this->getLoadedContextIds(),
			'ab-split entrypoint failed to resolve — likely a regression in resolveClassName()',
		);
		$this->assertContains('totalcms/ab-split', $this->getLoadedContextIds());
	}

	public function testContainerDefinitionsFromExtensionAreAppliedToDIContainer(): void
	{
		// addContainerDefinition() was once a no-op — the factories went into
		// the context's array and stayed there, never reaching the running
		// container. This test catches that regression: after register(),
		// the container should be able to resolve AbSplitMiddleware::class.
		$this->enableExtension('totalcms/ab-split');
		$this->manager->discoverAndRegister();

		// AbSplitMiddleware's factory needs TwigEngine — provide it now,
		// after bootAll-relevant work is done, so it's available for resolution.
		$this->container->set(TwigEngine::class, $this->createMock(TwigEngine::class));

		$instance = $this->container->get(AbSplitMiddleware::class);
		$this->assertInstanceOf(AbSplitMiddleware::class, $instance);
	}

	public function testPageMiddlewareNameIsRegisteredAfterBootAll(): void
	{
		$this->enableExtension('totalcms/ab-split');
		$this->manager->discoverAndRegister();
		$this->manager->bootAll();

		$registry = $this->container->get(PageMiddlewareRegistry::class);
		$this->assertContains('ab-split', $registry->availableNames());
	}

	public function testRegistryCanResolveAbSplitToARealMiddlewareInstance(): void
	{
		// The full chain: discovered → entrypoint loaded → sibling class
		// loaded → container def applied → name registered → resolve()
		// produces a working PageMiddlewareInterface.
		$this->enableExtension('totalcms/ab-split');
		$this->manager->discoverAndRegister();
		$this->manager->bootAll();

		$this->container->set(TwigEngine::class, $this->createMock(TwigEngine::class));

		$registry = $this->container->get(PageMiddlewareRegistry::class);
		$resolved = $registry->resolve('ab-split');

		$this->assertInstanceOf(AbSplitMiddleware::class, $resolved);
	}

	public function testDisabledBundledExtensionIsNotRegistered(): void
	{
		// Don't enable — the manager auto-records discovered extensions
		// with enabled=false on first run.
		$this->manager->discoverAndRegister();
		$this->manager->bootAll();

		$registry = $this->container->get(PageMiddlewareRegistry::class);
		$this->assertNotContains('ab-split', $registry->availableNames());
	}

	public function testCapabilityRevocationBlocksPageMiddlewareRegistration(): void
	{
		// Admin can disable individual capabilities. With page-middleware
		// turned off, the middleware should NOT register even when the
		// extension itself is enabled.
		$this->stateRepo->saveState('totalcms/ab-split', new ExtensionState(
			enabled: true,
			installedAt: date('c'),
			version: '0.0.0',
			permissions: ['container' => true, 'page-middleware' => false],
		));

		$this->manager->discoverAndRegister();
		$this->manager->bootAll();

		$registry = $this->container->get(PageMiddlewareRegistry::class);
		$this->assertNotContains('ab-split', $registry->availableNames());
	}

	private function enableExtension(string $id): void
	{
		$this->stateRepo->saveState($id, new ExtensionState(
			enabled: true,
			installedAt: date('c'),
			version: '0.0.0',
		));
	}

	/** @return list<string> */
	private function getLoadedContextIds(): array
	{
		// ExtensionManager doesn't expose contexts publicly. Use reflection
		// since this is a tightly-scoped integration test.
		$prop = new \ReflectionProperty($this->manager, 'contexts');

		return array_keys($prop->getValue($this->manager));
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
