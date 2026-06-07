<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/*
 * Extension container definitions are applied straight into the live PHP-DI
 * container ($container->set()). Without a guard, an extension could register
 * a definition under a CORE service ID (e.g. LoginService::class) and silently
 * replace core behavior — the one override channel Twig functions, MCP tools,
 * and CLI commands all already deny. These tests prove the strict-deny:
 * extension-owned IDs apply, core-namespace and known-entry IDs are skipped
 * with a warning, and the extension itself still loads.
 */

/** @return array{0: ExtensionManager, 1: DI\Container, 2: object} */
function containerGuardSetup(): array
{
	$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $fixturesDir;

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(true);
	$storage->method('read')->willReturn(json_encode([
		'test-vendor/container-defs-ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
	]));
	$storage->method('write')->willReturn(true);
	$stateRepo = new ExtensionStateRepository($storage);

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));

	$logger = new class extends AbstractLogger {
		/** @var list<array{level: mixed, message: string}> */
		public array $records = [];

		public function log($level, Stringable|string $message, array $context = []): void
		{
			$this->records[] = ['level' => $level, 'message' => (string)$message];
		}
	};

	// Real PHP-DI container so the definition-apply path actually runs.
	// 'core.protected-entry' stands in for an explicitly defined core entry.
	$container = new DI\Container();
	$container->set('core.protected-entry', (object)['source' => 'core']);

	$discovery = new ExtensionDiscovery($config, $manifestValidator, $logger);

	$manager = new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		$logger,
		$manifestValidator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);

	return [$manager, $container, $logger];
}

describe('Container definition override guard', function (): void {
	test('applies extension-owned definitions and denies protected ones', function (): void {
		[$manager, $container, $logger] = containerGuardSetup();

		$manager->discoverAndRegister();

		// The extension itself loads — a denied definition is skipped, not fatal.
		expect($manager->getLoadedExtensions())->toHaveKey('test-vendor/container-defs-ext');

		// Own service applied and resolvable.
		$own = $container->get('TestVendor\\ContainerDefsExt\\OwnService');
		expect($own->source)->toBe('extension');

		// Known core entry NOT overridden.
		expect($container->get('core.protected-entry')->source)->toBe('core');

		// Core-namespace ID NOT set as an extension definition. (The container
		// would still autowire the real class on get(); the hijack factory must
		// not be installed.)
		$warnings = array_column($logger->records, 'message');
		expect($warnings)->toContain("Extension 'test-vendor/container-defs-ext' attempted to override protected service 'TotalCMS\\Domain\\Auth\\Service\\LoginService'; definition skipped.");
		expect($warnings)->toContain("Extension 'test-vendor/container-defs-ext' attempted to override protected service 'core.protected-entry'; definition skipped.");
	});

	test('bundled namespace is exempt from the core-namespace rule', function (): void {
		[$manager] = containerGuardSetup();

		$reflection = new ReflectionMethod($manager, 'isProtectedServiceId');

		expect($reflection->invoke($manager, 'TotalCMS\\Bundled\\Pushover\\PushoverService', []))->toBeFalse()
			->and($reflection->invoke($manager, TotalCMS\Domain\Auth\Service\LoginService::class, []))->toBeTrue()
			->and($reflection->invoke($manager, 'Acme\\Anything\\Service', []))->toBeFalse()
			->and($reflection->invoke($manager, 'Acme\\Anything\\Service', ['Acme\\Anything\\Service' => 0]))->toBeTrue();
	});
});
