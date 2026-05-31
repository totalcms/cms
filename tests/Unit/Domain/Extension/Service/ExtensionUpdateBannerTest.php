<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

// ---------------------------------------------------------------------------
// Helper: state repo + manager pointing at the test fixtures directory
// ---------------------------------------------------------------------------

/**
 * @param array<string,ExtensionState> $seed
 */
function updateBannerStateRepo(array $seed = []): ExtensionStateRepository
{
	$files = [];
	if ($seed !== []) {
		$payload = [];
		foreach ($seed as $id => $state) {
			$payload[$id] = $state->toArray();
		}
		$files['.system/extensions.json'] = (string)json_encode($payload);
	}

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturnCallback(
		fn (string $location): bool => isset($files[$location]),
	);
	$storage->method('read')->willReturnCallback(
		fn (string $location): string => $files[$location] ?? '',
	);
	$storage->method('write')->willReturnCallback(
		function (string $location, string $contents) use (&$files): bool {
			$files[$location] = $contents;

			return true;
		},
	);
	$storage->method('move')->willReturnCallback(
		function (string $old, string $new) use (&$files): bool {
			$files[$new] = $files[$old] ?? '';
			unset($files[$old]);

			return true;
		},
	);

	return new ExtensionStateRepository($storage);
}

function updateBannerManager(ExtensionStateRepository $stateRepo): ExtensionManager
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = dirname(__DIR__, 4) . '/fixtures';

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));
	$discovery         = new ExtensionDiscovery($config, $manifestValidator, new NullLogger());
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	return new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
		$manifestValidator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);
}

/**
 * Build a minimal Twig environment that can render the update-banner partial.
 * Registers stub versions of cms.dashboard and csrf_field() so the partial
 * does not depend on the full application container.
 */
function updateBannerTwig(): Environment
{
	$loader = new FilesystemLoader(
		dirname(__DIR__, 5) . '/resources/templates',
	);
	$twig = new Environment($loader, ['autoescape' => 'html']);

	$twig->addFunction(new TwigFunction('csrf_field', fn (): string => '<input type="hidden" name="csrf_token" value="test-token">'));

	// Stub `cms` global that exposes a `dashboard` property.
	$cms = new class {
		public string $dashboard = '/admin';
	};
	$twig->addGlobal('cms', $cms);

	return $twig;
}

// ---------------------------------------------------------------------------
// Tests: buildExtensionInfo exposes updateDisabled fields
// ---------------------------------------------------------------------------

describe('buildExtensionInfo — updateDisabled fields', function (): void {
	test('exposes updateDisabled=true, reason, and findings when marker is set', function (): void {
		$state = new ExtensionState(
			enabled: false,
			installedAt: '',
			version: '1.0.0',
			updateDisabled: [
				'reason'    => 'eval() detected',
				'findings'  => 2,
				'updatedAt' => '2026-05-31T00:00:00Z',
			],
		);
		$stateRepo = updateBannerStateRepo(['test-vendor/hello-world' => $state]);
		$manager   = updateBannerManager($stateRepo);

		$manager->discoverAndRegister();

		$info = $manager->getExtension('test-vendor/hello-world');

		expect($info)->not->toBeNull()
			->and($info['updateDisabled'])->toBeTrue()
			->and($info['updateDisabledReason'])->toBe('eval() detected')
			->and($info['updateFindings'])->toBe(2);
	});

	test('exposes updateDisabled=false, null reason, null findings when no marker', function (): void {
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '1.0.0');
		$stateRepo = updateBannerStateRepo(['test-vendor/hello-world' => $state]);
		$manager   = updateBannerManager($stateRepo);

		$manager->discoverAndRegister();

		$info = $manager->getExtension('test-vendor/hello-world');

		expect($info)->not->toBeNull()
			->and($info['updateDisabled'])->toBeFalse()
			->and($info['updateDisabledReason'])->toBeNull()
			->and($info['updateFindings'])->toBeNull();
	});
});

// ---------------------------------------------------------------------------
// Tests: enable() clears the updateDisabled marker
// ---------------------------------------------------------------------------

describe('enable() clears the updateDisabled marker', function (): void {
	test('enable() clears updateDisabled so the banner disappears after re-enable', function (): void {
		$state = new ExtensionState(
			enabled: false,
			installedAt: '',
			version: '1.0.0',
			updateDisabled: [
				'reason'    => 'risky code detected',
				'findings'  => 3,
				'updatedAt' => '2026-05-31T00:00:00Z',
			],
		);
		$stateRepo = updateBannerStateRepo(['test-vendor/hello-world' => $state]);
		$manager   = updateBannerManager($stateRepo);

		$manager->discoverAndRegister();
		$manager->enable('test-vendor/hello-world');

		$after = $stateRepo->getState('test-vendor/hello-world');
		expect($after)->not->toBeNull()
			->and($after->enabled)->toBeTrue()
			->and($after->isUpdateDisabled())->toBeFalse()
			->and($after->updateDisabled)->toBeNull();
	});

	test('enable() is idempotent when there is no updateDisabled marker', function (): void {
		$state     = new ExtensionState(enabled: false, installedAt: '', version: '1.0.0');
		$stateRepo = updateBannerStateRepo(['test-vendor/hello-world' => $state]);
		$manager   = updateBannerManager($stateRepo);

		$manager->discoverAndRegister();
		$manager->enable('test-vendor/hello-world');

		$after = $stateRepo->getState('test-vendor/hello-world');
		expect($after)->not->toBeNull()
			->and($after->enabled)->toBeTrue()
			->and($after->isUpdateDisabled())->toBeFalse();
	});
});

// ---------------------------------------------------------------------------
// Tests: extension-update-banner.twig template rendering
// ---------------------------------------------------------------------------

describe('extension-update-banner.twig template', function (): void {
	test('renders the warning box with findings count and reason when updateDisabled is true', function (): void {
		$twig = updateBannerTwig();

		$html = $twig->render('admin/partials/extension-update-banner.twig', [
			'updateDisabled'       => true,
			'updateDisabledReason' => 'eval() detected',
			'updateFindings'       => 3,
			'extId'                => 'test-vendor/hello-world',
		]);

		expect($html)
			->toContain('warning-box')
			->toContain('role="alert"')
			->toContain('Disabled after an update')
			->toContain('3 risky code patterns')
			->toContain('eval() detected')
			->toContain('Review the changes before re-enabling')
			->toContain('Review &amp; re-enable')
			->toContain('action="/admin/extensions/test-vendor/hello-world/enable"');
	});

	test('uses singular "pattern" when findings is 1', function (): void {
		$twig = updateBannerTwig();

		$html = $twig->render('admin/partials/extension-update-banner.twig', [
			'updateDisabled'       => true,
			'updateDisabledReason' => null,
			'updateFindings'       => 1,
			'extId'                => 'acme/my-ext',
		]);

		expect($html)
			->toContain('1 risky code pattern')
			->not->toContain('patterns');
	});

	test('omits the findings clause when updateFindings is null', function (): void {
		$twig = updateBannerTwig();

		$html = $twig->render('admin/partials/extension-update-banner.twig', [
			'updateDisabled'       => true,
			'updateDisabledReason' => null,
			'updateFindings'       => null,
			'extId'                => 'acme/my-ext',
		]);

		expect($html)
			->toContain('was installed')
			->not->toContain('risky code pattern');
	});

	test('escapes the reason to prevent XSS', function (): void {
		$twig = updateBannerTwig();

		$html = $twig->render('admin/partials/extension-update-banner.twig', [
			'updateDisabled'       => true,
			'updateDisabledReason' => '<script>alert(1)</script>',
			'updateFindings'       => 2,
			'extId'                => 'acme/my-ext',
		]);

		expect($html)
			->not->toContain('<script>')
			->toContain('&lt;script&gt;');
	});

	test('renders nothing when updateDisabled is false', function (): void {
		$twig = updateBannerTwig();

		$html = $twig->render('admin/partials/extension-update-banner.twig', [
			'updateDisabled'       => false,
			'updateDisabledReason' => 'some reason',
			'updateFindings'       => 5,
			'extId'                => 'acme/my-ext',
		]);

		expect(trim($html))->toBe('');
	});

	test('includes csrf_field() output in the re-enable form', function (): void {
		$twig = updateBannerTwig();

		$html = $twig->render('admin/partials/extension-update-banner.twig', [
			'updateDisabled'       => true,
			'updateDisabledReason' => null,
			'updateFindings'       => 1,
			'extId'                => 'acme/my-ext',
		]);

		expect($html)->toContain('csrf_token');
	});
});
