<?php

declare(strict_types=1);

/**
 * Extension Profiling — END-TO-END integration test.
 *
 * This is the production-path proof for "Extension Stability — Plan 2:
 * Performance Visibility". It mirrors ExtensionCrashProofingTest's harness:
 * it resolves the real TwigEngine from the application container, registers a
 * guarded extension Twig function through the real boot path
 * (ExtensionManager::getAllTwigFunctions → TwigExtensionRegistrar::filterAndRegister),
 * and renders a real template STRING through TwigEngine::renderString().
 *
 * The point: prove that timing flows end-to-end through REAL Twig rendering
 * into the flushed cache metrics, and that sampling gates it. The SAME profiler
 * instance the guard times with is the one we flush + inspect.
 */

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Extension\Service\TwigExtensionRegistrar;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use Twig\TwigFunction;

/**
 * In-memory CacheManager whose backing store is inspectable, so the test can
 * read back what the profiler's flush() wrote to `extprof:{id}`. Uses a
 * by-reference store (NOT arrow functions) so flush()/metricsFor() share state.
 */
function profilingCache(): CacheManager
{
	return new class extends CacheManager {
		/** @var array<string,mixed> */
		public array $store = [];

		public function __construct()
		{
			// Bypass the heavy parent constructor — the profiler only touches
			// getData()/storeData() for its rolling metrics.
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function getOperationalData(string $key): mixed
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
 * Build an ExtensionProfiler with explicit env/preview/sampleRate, backed by the
 * supplied inspectable cache. The returned profiler is the SINGLE instance the
 * test passes to the guard (timing-during-render) AND flushes + inspects.
 */
function profilingProfiler(string $env, bool $isPreview, int $sampleRate, CacheManager $cache): ExtensionProfiler
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	$resolver    = new EnvironmentResolver($config, $isPreview);

	return new ExtensionProfiler($resolver, $cache, $sampleRate, new NullLogger());
}

/**
 * Real ExtensionStateRepository backed by a fresh temp directory.
 *
 * @return array{0: ExtensionStateRepository, 1: string}
 */
function profilingStateRepo(): array
{
	$tmpRoot = sys_get_temp_dir() . '/tcms-profiling-' . bin2hex(random_bytes(6));
	mkdir($tmpRoot, 0777, true);

	$flysystem = new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter($tmpRoot));
	$storage   = new StorageFilesystemAdapter($flysystem);

	return [new ExtensionStateRepository($storage), $tmpRoot];
}

/**
 * Build a real ExtensionGuard wired with the SAME profiler the test inspects, so
 * the timing recorded during render lands in that profiler's totals.
 */
function profilingGuard(
	string $env,
	bool $isPreview,
	ExtensionStateRepository $repo,
	CacheManager $cache,
	ExtensionProfiler $profiler,
): ExtensionGuard {
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	$resolver    = new EnvironmentResolver($config, $isPreview);

	return new ExtensionGuard($resolver, $cache, $repo, new NullLogger(), $profiler, 5);
}

/**
 * Build a real ExtensionManager whose only loaded context is `acme/widget`,
 * registering whatever Twig functions $register() adds. Uses the supplied guard
 * + profiler so timing flows through the real getAllTwigFunctions() guard path.
 *
 * @param callable(ExtensionContext): void $register
 *
 * @return array{0: ExtensionManager, 1: ExtensionContext}
 */
function profilingManager(
	ExtensionGuard $guard,
	ExtensionStateRepository $repo,
	ExtensionProfiler $profiler,
	callable $register,
): array {
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
		$profiler,
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
function profilingTwigEngine(ExtensionManager $manager): TwigEngine
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

describe('Extension profiling through real Twig', function (): void {
	test('dev profiles a guarded render and flush persists readable metrics', function (): void {
		[$repo, $tmpRoot] = profilingStateRepo();
		$cache            = profilingCache();
		// dev always profiles regardless of sampleRate.
		$profiler = profilingProfiler('dev', false, 999, $cache);
		$guard    = profilingGuard('dev', false, $repo, $cache, $profiler);

		expect($profiler->isProfiling())->toBeTrue();

		[$manager] = profilingManager($guard, $repo, $profiler, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_slow', function (): string {
				// 5ms — comfortably above the (int)(ns/1000) micros truncation
				// floor so avgMs/lastMs round to a non-zero value.
				usleep(5000);

				return 'x';
			}));
		});

		$twigEngine = profilingTwigEngine($manager);

		// REAL Twig: compile + render a template string referencing the function.
		$output = $twigEngine->renderString('{{ ext_slow() }}');
		expect($output)->toBe('x');

		// Nothing written until flush().
		$profiler->flush();

		$metrics = $profiler->metricsFor('acme/widget');

		// Guarded render → profiler.time accumulated → flush persisted →
		// metricsFor read it back.
		expect($metrics)->not->toBeNull();
		expect($metrics['avgMs'])->toBeGreaterThan(0);
		expect($metrics['lastMs'])->toBeGreaterThan(0);
		expect($metrics['samples'])->toBeGreaterThanOrEqual(1);

		recursiveDelete($tmpRoot, forceComplete: true);
	});

	test('prod with sampleRate 0 does not profile — flush writes no metric', function (): void {
		[$repo, $tmpRoot] = profilingStateRepo();
		$cache            = profilingCache();
		// prod + sampleRate 0 ⇒ isProfiling() false ⇒ timers never run.
		$profiler = profilingProfiler('prod', false, 0, $cache);
		$guard    = profilingGuard('prod', false, $repo, $cache, $profiler);

		expect($profiler->isProfiling())->toBeFalse();

		[$manager] = profilingManager($guard, $repo, $profiler, function (ExtensionContext $ctx): void {
			$ctx->addTwigFunction(new TwigFunction('ext_slow', function (): string {
				usleep(5000);

				return 'x';
			}));
		});

		$twigEngine = profilingTwigEngine($manager);

		// The function still renders fine — profiling-off only skips measurement.
		$output = $twigEngine->renderString('{{ ext_slow() }}');
		expect($output)->toBe('x');

		$profiler->flush();

		// No metric written — timers never ran, so sampling-off = zero measurement.
		expect($profiler->metricsFor('acme/widget'))->toBeNull();
		expect($cache->store['extprof:acme/widget'] ?? null)->toBeNull();

		recursiveDelete($tmpRoot, forceComplete: true);
	});
});
