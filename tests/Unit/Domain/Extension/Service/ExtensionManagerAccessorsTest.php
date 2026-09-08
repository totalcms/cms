<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * The permission-filtered accessors added for the boot steps, plus the
 * shared permittedContexts() iterator they and the older accessors use.
 * Contexts are injected by reflection (ExtensionCrashProofingTest pattern).
 */

/** @param array<string,ExtensionState> $states */
function accessorManager(array $states): ExtensionManager
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = sys_get_temp_dir();
	$storage         = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);
	$repo = new ExtensionStateRepository($storage);
	foreach ($states as $id => $state) {
		$repo->saveState($id, $state);
	}
	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$container = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	return new ExtensionManager(
		new ExtensionDiscovery($config, $validator, new NullLogger(), bundledExtensionsDir: $config->datadir . '/no-bundled-here'),
		$repo,
		new ExtensionDependencySorter(),
		new ExtensionSettingsManager($settingsStorage),
		$container,
		new NullLogger(),
		$validator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);
}

function accessorContext(ExtensionManager $manager, string $id, string $path, callable $register): void
{
	$container = (new ReflectionProperty(ExtensionManager::class, 'container'))->getValue($manager);
	$settings  = (new ReflectionProperty(ExtensionManager::class, 'settingsManager'))->getValue($manager);
	$ctx       = new ExtensionContext(ExtensionManifest::fromArray(['id' => $id, 'name' => $id]), $path, $container, $settings, new NullLogger());
	$register($ctx);
	$prop     = new ReflectionProperty(ExtensionManager::class, 'contexts');
	$contexts = $prop->getValue($manager);
	$contexts[$id] = $ctx;
	$prop->setValue($manager, $contexts);
}

beforeEach(function (): void {
	$this->tmp = sys_get_temp_dir() . '/tcms-accessors-' . uniqid();
	mkdir($this->tmp . '/with/schemas', 0777, true);
	mkdir($this->tmp . '/with/templates', 0777, true);
	mkdir($this->tmp . '/without', 0777, true);
});

afterEach(function (): void {
	recursiveDelete($this->tmp, forceComplete: true);
});

test('getAllSchemaDirs lists only permitted extensions whose schemas/ dir exists', function (): void {
	$manager = accessorManager([
		'a/with'   => new ExtensionState(enabled: true, permissions: ['schemas' => true]),
		'b/denied' => new ExtensionState(enabled: true, permissions: ['schemas' => false]),
	]);
	accessorContext($manager, 'a/with', $this->tmp . '/with', fn () => null);
	accessorContext($manager, 'b/denied', $this->tmp . '/with', fn () => null);
	accessorContext($manager, 'c/nodir', $this->tmp . '/without', fn () => null);

	expect($manager->getAllSchemaDirs())->toBe(['a/with' => $this->tmp . '/with/schemas']);
});

test('getAllTemplatePaths maps vendor-name namespaces to existing templates/ dirs, ungated', function (): void {
	$manager = accessorManager(['a/with' => new ExtensionState(enabled: true, permissions: ['twig:functions' => false])]);
	accessorContext($manager, 'a/with', $this->tmp . '/with', fn () => null);
	accessorContext($manager, 'c/nodir', $this->tmp . '/without', fn () => null);

	expect($manager->getAllTemplatePaths())->toBe(['a-with' => $this->tmp . '/with/templates']);
});

test('getAllTemplatePaths uses the manifest vendor()-shortName() when one is on record', function (): void {
	$manager = accessorManager([]);
	accessorContext($manager, 'raw-context-id', $this->tmp . '/with', fn () => null);

	// discoveredManifests is keyed by context id, not by the manifest's own
	// id field — inject a manifest under 'raw-context-id' whose internal id
	// differs, so the namespace it produces ('a-with') can only have come
	// from vendor()/shortName(), not from the str_replace('/', '-', $id) fallback.
	$manifests                          = (new ReflectionProperty(ExtensionManager::class, 'discoveredManifests'))->getValue($manager);
	$manifests['raw-context-id']        = ExtensionManifest::fromArray(['id' => 'a/with', 'name' => 'With']);
	(new ReflectionProperty(ExtensionManager::class, 'discoveredManifests'))->setValue($manager, $manifests);

	expect($manager->getAllTemplatePaths())->toBe(['a-with' => $this->tmp . '/with/templates']);
});

test('getAllPageMiddleware and getAllFormActions are gated by their capabilities', function (): void {
	$manager = accessorManager([
		'a/on'  => new ExtensionState(enabled: true, permissions: ['page-middleware' => true, 'form-actions' => true]),
		'b/off' => new ExtensionState(enabled: true, permissions: ['page-middleware' => false, 'form-actions' => false]),
	]);
	$register = function (ExtensionContext $ctx): void {
		$ctx->addPageMiddleware('mw', 'Vendor\\Mw');
		$ctx->addFormAction('fa', new FormAction('fa', '/fa', 'FA'));
	};
	accessorContext($manager, 'a/on', $this->tmp . '/without', $register);
	accessorContext($manager, 'b/off', $this->tmp . '/without', $register);

	expect($manager->getAllPageMiddleware())->toBe(['a/on' => ['mw' => 'Vendor\\Mw']])
		->and(array_keys($manager->getAllFormActions()))->toBe(['a/on'])
		->and($manager->getAllFormActions()['a/on'][0]->name)->toBe('fa');
});

test('an extension with no stored state is permitted everything', function (): void {
	$manager = accessorManager([]);
	accessorContext($manager, 'x/unknown', $this->tmp . '/with', fn (ExtensionContext $ctx) => $ctx->addPageMiddleware('mw', 'V\\M'));

	expect($manager->getAllSchemaDirs())->toHaveKey('x/unknown')
		->and($manager->getAllPageMiddleware())->toHaveKey('x/unknown');
});
