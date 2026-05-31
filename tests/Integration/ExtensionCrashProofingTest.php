<?php

declare(strict_types=1);

/**
 * Extension Crash-Proofing — END-TO-END integration test.
 *
 * This is the production-path proof for "Extension Stability — Plan 1". The unit
 * suite (ExtensionManagerGuardTest) invokes the rebuilt guarded callables
 * directly; THIS test compiles and renders a real Twig template STRING through
 * the real TwigEngine after the extension's guarded Twig functions have been
 * registered exactly the way boot does it (ExtensionManager::getAllTwigFunctions
 * → TwigExtensionRegistrar::filterAndRegister → TwigEngine::registerExtensionItems).
 *
 * The point: a throwing extension Twig function rendered through real Twig must
 * degrade to empty output instead of white-screening the page (a 500).
 */

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Extension\Service\TwigExtensionRegistrar;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use Twig\TwigFunction;

/**
 * In-memory CacheManager whose failure-counter store is inspectable, so the test
 * can read the guard's `extguard:fail:{id}` key directly (assertion 3).
 */
function crashProofCache(): CacheManager
{
	return new class extends CacheManager {
		/** @var array<string,mixed> */
		public array $store = [];

		public function __construct()
		{
			// Bypass the heavy parent constructor — the guard only touches
			// getData()/storeData() for its rolling failure counter.
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			$this->store[$key] = $data;

			return true;
		}
	};
}

/**
 * Build an ExtensionGuard with explicit env/threshold and a shared state repo +
 * cache, so the test can drive quarantine behaviour deterministically.
 */
function crashProofGuard(
	string $env,
	bool $isPreview,
	int $threshold,
	ExtensionStateRepository $repo,
	CacheManager $cache,
): ExtensionGuard {
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	$resolver    = new EnvironmentResolver($config, $isPreview);

	return new ExtensionGuard($resolver, $cache, $repo, new NullLogger(), testExtensionProfiler(), $threshold);
}

/**
 * Real ExtensionStateRepository backed by a fresh temp directory. Returned with
 * its temp root so the caller can clean up.
 *
 * @return array{0: ExtensionStateRepository, 1: string}
 */
function crashProofStateRepo(): array
{
	$tmpRoot = sys_get_temp_dir() . '/tcms-crashproof-' . bin2hex(random_bytes(6));
	mkdir($tmpRoot, 0777, true);

	$flysystem = new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter($tmpRoot));
	$storage   = new StorageFilesystemAdapter($flysystem);

	return [new ExtensionStateRepository($storage), $tmpRoot];
}

/**
 * Build a real ExtensionManager whose only loaded context is `acme/widget`,
 * registering whatever Twig functions $register() adds. Uses the supplied guard
 * + state repo so the test controls the quarantine pipeline.
 *
 * @param callable(ExtensionContext): void $register
 *
 * @return array{0: ExtensionManager, 1: ExtensionContext}
 */
function crashProofManager(ExtensionGuard $guard, ExtensionStateRepository $repo, callable $register): array
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = sys_get_temp_dir();

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));
	$discovery         = new ExtensionDiscovery($config, $manifestValidator, new NullLogger());
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	$manager = new ExtensionManager(
		$discovery,
		$repo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
		$manifestValidator,
		$guard,
		testExtensionProfiler(),
	);

	$manifest = ExtensionManifest::fromArray(['id' => 'acme/widget', 'name' => 'Widget']);
	$context  = new ExtensionContext($manifest, '/tmp/acme-widget', $container, $settingsManager, new NullLogger());

	$register($context);

	$ref = new ReflectionProperty(ExtensionManager::class, 'contexts');
	$ref->setValue($manager, ['acme/widget' => $context]);

	return [$manager, $context];
}

/**
 * Resolve a real, fully-wired TwigEngine from the application container, then
 * register the extension's collected (guarded) Twig functions onto it exactly
 * the way boot does — through TwigExtensionRegistrar::filterAndRegister.
 */
function crashProofTwigEngine(ExtensionManager $manager): TwigEngine
{
	$app       = bootstrap();
	$container = $app->getContainer();

	/** @var TwigEngine $twigEngine */
	$twigEngine = $container->get(TwigEngine::class);
	/** @var TotalCMSTwigExtension $coreExtension */
	$coreExtension = $container->get(TotalCMSTwigExtension::class);

	$registrar = new TwigExtensionRegistrar(new NullLogger());
	$registrar->filterAndRegister(
		$twigEngine,
		$coreExtension,
		$manager->getAllTwigFunctions(),
		$manager->getAllTwigFilters(),
		[],
	);

	return $twigEngine;
}

describe('Extension crash-proofing through real Twig', function (): void {
	test('a throwing extension Twig function degrades to empty output (no white-screen)', function (): void {
		[$repo, $tmpRoot] = crashProofStateRepo();
		$cache            = crashProofCache();
		$guard            = crashProofGuard('dev', false, 5, $repo, $cache);

		[$manager] = crashProofManager($guard, $repo, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_boom', function (): string {
				throw new RuntimeException('kaboom');
			}));
		});

		$twigEngine = crashProofTwigEngine($manager);

		// REAL Twig: compile + render a template string referencing the function.
		$output = $twigEngine->renderString('before {{ ext_boom() }} after');

		// The render SUCCEEDS and the function's slot is empty — not a 500.
		expect($output)->toBe('before  after');

		recursiveDelete($tmpRoot, forceComplete: true);
	});

	test('a healthy extension Twig function renders its real value through the guard', function (): void {
		[$repo, $tmpRoot] = crashProofStateRepo();
		$cache            = crashProofCache();
		$guard            = crashProofGuard('dev', false, 5, $repo, $cache);

		[$manager] = crashProofManager($guard, $repo, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_ok', fn (): string => 'OK'));
		});

		$twigEngine = crashProofTwigEngine($manager);

		$output = $twigEngine->renderString('value: {{ ext_ok() }}');

		expect($output)->toBe('value: OK');

		recursiveDelete($tmpRoot, forceComplete: true);
	});

	test('a throwing render feeds the quarantine pipeline (failure counter increments)', function (): void {
		[$repo, $tmpRoot] = crashProofStateRepo();
		$cache            = crashProofCache();
		$guard            = crashProofGuard('dev', false, 5, $repo, $cache);

		[$manager] = crashProofManager($guard, $repo, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_boom', function (): string {
				throw new RuntimeException('kaboom');
			}));
		});

		$twigEngine = crashProofTwigEngine($manager);

		expect($cache->store['extguard:fail:acme/widget'] ?? null)->toBeNull();

		$twigEngine->renderString('{{ ext_boom() }}');

		// The crash was counted via the guard's rolling failure counter.
		expect($cache->store['extguard:fail:acme/widget'] ?? null)->toBe(1);

		recursiveDelete($tmpRoot, forceComplete: true);
	});

	test('on prod with threshold 1, one throwing render quarantines the extension', function (): void {
		[$repo, $tmpRoot] = crashProofStateRepo();
		$cache            = crashProofCache();

		// A real, enabled state must exist for the guard to mutate it.
		$repo->saveState('acme/widget', new ExtensionState(enabled: true));

		$guard = crashProofGuard('prod', false, 1, $repo, $cache);

		[$manager] = crashProofManager($guard, $repo, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_boom', function (): string {
				throw new RuntimeException('kaboom');
			}));
		});

		$twigEngine = crashProofTwigEngine($manager);

		expect($repo->getState('acme/widget')?->isQuarantined())->toBeFalse();

		$twigEngine->renderString('{{ ext_boom() }}');

		// Quarantine persisted through saveState (repo keeps its in-memory cache
		// in sync on write, so getState reflects the mutation).
		expect($repo->getState('acme/widget')?->isQuarantined())->toBeTrue();

		recursiveDelete($tmpRoot, forceComplete: true);
	});

	test('on dev, a throwing render never quarantines regardless of threshold', function (): void {
		[$repo, $tmpRoot] = crashProofStateRepo();
		$cache            = crashProofCache();

		$repo->saveState('acme/widget', new ExtensionState(enabled: true));

		$guard = crashProofGuard('dev', false, 1, $repo, $cache);

		[$manager] = crashProofManager($guard, $repo, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_boom', function (): string {
				throw new RuntimeException('kaboom');
			}));
		});

		$twigEngine = crashProofTwigEngine($manager);

		$twigEngine->renderString('{{ ext_boom() }}');

		expect($repo->getState('acme/widget')?->isQuarantined())->toBeFalse();

		recursiveDelete($tmpRoot, forceComplete: true);
	});
});
