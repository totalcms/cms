<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Build a bare ExtensionManager and inject a single ExtensionContext into its
 * private $contexts array. This exercises the collection accessors
 * (getAllEventListeners / getAllTwigFunctions / getAllTwigFilters) in isolation
 * without needing a fixture extension on disk — the test controls exactly what
 * hooks the context exposes (including throwing ones).
 *
 * @return array{0: ExtensionManager, 1: ExtensionContext}
 */
function guardManagerWithContext(): array
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = sys_get_temp_dir();

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);
	$stateRepo = new ExtensionStateRepository($storage);

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));
	$discovery         = new ExtensionDiscovery($config, $manifestValidator, new NullLogger());
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	$manager = new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
		$manifestValidator,
		testExtensionGuard(),
	);

	$manifest = ExtensionManifest::fromArray(['id' => 'acme/widget', 'name' => 'Widget']);
	$context  = new ExtensionContext($manifest, '/tmp/acme-widget', $container, $settingsManager, new NullLogger());

	// Inject the context into the manager's private $contexts map so the
	// collection accessors will pick it up.
	$ref  = new ReflectionProperty(ExtensionManager::class, 'contexts');
	$ref->setValue($manager, ['acme/widget' => $context]);

	return [$manager, $context];
}

describe('ExtensionManager guard wrapping', function (): void {
	test('a throwing event listener is contained and returns null', function (): void {
		[$manager, $context] = guardManagerWithContext();

		$context->addEventListener('object.created', function (): void {
			throw new RuntimeException('listener boom');
		});

		$collected = $manager->getAllEventListeners();

		expect($collected)->toHaveKey('object.created');

		[$callable] = $collected['object.created'][0];

		// Invoking the guarded listener must NOT throw.
		$result = $callable(['some' => 'payload']);

		expect($result)->toBeNull();
	});

	test('a throwing Twig function renders empty string instead of throwing', function (): void {
		[$manager, $context] = guardManagerWithContext();

		$context->addTwigFunction(new TwigFunction('explode_fn', function (): string {
			throw new RuntimeException('fn boom');
		}));

		$functions = $manager->getAllTwigFunctions();

		$fn = null;
		foreach ($functions as $candidate) {
			if ($candidate->getName() === 'explode_fn') {
				$fn = $candidate;
				break;
			}
		}

		expect($fn)->not->toBeNull();

		$callable = $fn->getCallable();
		expect($callable)->not->toBeNull();

		$result = $callable('arg');

		expect($result)->toBe('');
	});

	test('a throwing Twig filter renders empty string instead of throwing', function (): void {
		[$manager, $context] = guardManagerWithContext();

		$context->addTwigFilter(new TwigFilter('explode_filter', function (string $value): string {
			throw new RuntimeException('filter boom');
		}));

		$filters = $manager->getAllTwigFilters();

		$filter = null;
		foreach ($filters as $candidate) {
			if ($candidate->getName() === 'explode_filter') {
				$filter = $candidate;
				break;
			}
		}

		expect($filter)->not->toBeNull();

		$callable = $filter->getCallable();
		expect($callable)->not->toBeNull();

		$result = $callable('input');

		expect($result)->toBe('');
	});

	test('a healthy Twig function still returns its real value through the guard', function (): void {
		[$manager, $context] = guardManagerWithContext();

		$context->addTwigFunction(new TwigFunction('greet', fn (string $name): string => "hi {$name}"));

		$functions = $manager->getAllTwigFunctions();
		$fn        = null;
		foreach ($functions as $candidate) {
			if ($candidate->getName() === 'greet') {
				$fn = $candidate;
				break;
			}
		}

		expect($fn)->not->toBeNull();
		$callable = $fn->getCallable();

		expect($callable('joe'))->toBe('hi joe');
	});

	test('Twig function options are preserved through the guard wrap', function (): void {
		[$manager, $context] = guardManagerWithContext();

		$context->addTwigFunction(new TwigFunction(
			'safe_html',
			fn (): string => '<b>x</b>',
			['is_safe' => ['html'], 'needs_context' => true, 'needs_environment' => true],
		));

		$functions = $manager->getAllTwigFunctions();
		$fn        = null;
		foreach ($functions as $candidate) {
			if ($candidate->getName() === 'safe_html') {
				$fn = $candidate;
				break;
			}
		}

		expect($fn)->not->toBeNull();
		// Options must survive the wrap: needs_context / needs_environment flags
		// drive how Twig prepends args (public, stable accessors).
		expect($fn->needsContext())->toBeTrue();
		expect($fn->needsEnvironment())->toBeTrue();

		// is_safe lives on the protected $options property in Twig 4 — read it the
		// same way the guard rebuild does to prove the flag is preserved verbatim.
		$optionsProp = new ReflectionProperty(\Twig\AbstractTwigCallable::class, 'options');
		$options     = $optionsProp->getValue($fn);
		expect($options['is_safe'])->toBe(['html']);
	});
});
