<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Admin\ExtensionToggleAction;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * Builds a real ExtensionManager over the test fixtures (clean + dangerous
 * extensions) so the manager behaves exactly as in production. Self-contained
 * so a single-file run doesn't depend on another test file's helper.
 */
function makeToggleReviewManager(string $extensionsDir): ExtensionManager
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $extensionsDir;

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

function makeToggleAction(ExtensionManager $manager): ExtensionToggleAction
{
	$routeParser = test()->createMock(RouteParserInterface::class);
	$routeParser->method('urlFor')->willReturnCallback(
		static function (string $name, array $data = []): string {
			if ($name === 'admin-extension-review') {
				return '/admin/extensions/' . ($data['extension'] ?? '') . '/review';
			}

			return '/admin/extensions';
		},
	);

	return new ExtensionToggleAction($manager, $routeParser);
}

describe('ExtensionToggleAction enable path', function (): void {
	test('a clean disabled extension enables directly without a review redirect', function (): void {
		$manager = makeToggleReviewManager(dirname(__DIR__, 3) . '/fixtures');
		$manager->discoverAndRegister();

		$action  = makeToggleAction($manager);
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/extensions/test-vendor/clean-ext/enable');

		$result = $action($request, new Response(), [
			'extension' => 'test-vendor/clean-ext',
			'action'    => 'enable',
		]);

		// Redirected back to the listing (not the review page) and actually enabled.
		expect($result->getStatusCode())->toBe(302);
		expect($result->getHeaderLine('Location'))->toBe('/admin/extensions');
		expect($manager->isEnabled('test-vendor/clean-ext'))->toBeTrue();
	});

	test('a flagged disabled extension without confirmation redirects to review and is NOT enabled', function (): void {
		$manager = makeToggleReviewManager(dirname(__DIR__, 3) . '/fixtures');
		$manager->discoverAndRegister();

		$action  = makeToggleAction($manager);
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/extensions/test-vendor/dangerous-ext/enable');

		$result = $action($request, new Response(), [
			'extension' => 'test-vendor/dangerous-ext',
			'action'    => 'enable',
		]);

		expect($result->getStatusCode())->toBe(302);
		expect($result->getHeaderLine('Location'))->toBe('/admin/extensions/test-vendor/dangerous-ext/review');
		expect($manager->isEnabled('test-vendor/dangerous-ext'))->toBeFalse();
	});

	test('a flagged disabled extension with confirmed=1 enables directly', function (): void {
		$manager = makeToggleReviewManager(dirname(__DIR__, 3) . '/fixtures');
		$manager->discoverAndRegister();

		$action  = makeToggleAction($manager);
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', '/admin/extensions/test-vendor/dangerous-ext/enable')
			->withParsedBody(['confirmed' => '1']);

		$result = $action($request, new Response(), [
			'extension' => 'test-vendor/dangerous-ext',
			'action'    => 'enable',
		]);

		expect($result->getStatusCode())->toBe(302);
		expect($result->getHeaderLine('Location'))->toBe('/admin/extensions');
		expect($manager->isEnabled('test-vendor/dangerous-ext'))->toBeTrue();
	});
});
