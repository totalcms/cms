<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Admin\AdminExtensionsAction;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Renders the Extensions page over the test fixtures and returns the data
 * handed to the template.
 *
 * @param array<string,mixed> $query
 *
 * @return array<string,mixed>
 */
function renderExtensionsPage(array $query): array
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = dirname(__DIR__, 3) . '/fixtures';

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);

	$manifestValidator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	$manager = new ExtensionManager(
		new ExtensionDiscovery($config, $manifestValidator, new NullLogger()),
		new ExtensionStateRepository($storage),
		new ExtensionDependencySorter(),
		new ExtensionSettingsManager($settingsStorage),
		$container,
		new NullLogger(),
		$manifestValidator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);
	$manager->discoverAndRegister();

	$rendered = [];
	$twig     = test()->createMock(TwigRenderer::class);
	$twig->method('template')->willReturnCallback(
		static function (ResponseInterface $response, string $template, array $data = []) use (&$rendered): ResponseInterface {
			$rendered = $data;

			return $response;
		},
	);

	$request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/extensions')->withQueryParams($query);
	(new AdminExtensionsAction($twig, $manager, $config))($request, new Response(), []);

	return $rendered;
}

describe('AdminExtensionsAction installed highlight', function (): void {
	test('an installed id on the page is passed through as that extension', function (): void {
		$data = renderExtensionsPage(['installed' => 'test-vendor/clean-ext']);

		expect($data['installed'])->toBeArray()
			->and($data['installed']['id'])->toBe('test-vendor/clean-ext')
			->and($data['installed']['name'])->toBe('Clean Ext (fixture)');
	});

	test('no installed parameter means no highlight', function (): void {
		expect(renderExtensionsPage([])['installed'])->toBeNull();
	});

	test('a malformed or unknown installed value is ignored', function (string $value): void {
		expect(renderExtensionsPage(['installed' => $value])['installed'])->toBeNull();
	})->with([
		'unknown extension' => 'test-vendor/no-such-ext',
		'no vendor'         => 'clean-ext',
		'upper case'        => 'Test-Vendor/Clean-Ext',
		'markup'            => '<script>/x',
		'trailing newline'  => "test-vendor/clean-ext\n",
		'extra segment'     => 'test-vendor/clean-ext/x',
	]);

	test('a non-string installed value is ignored', function (): void {
		$data = renderExtensionsPage(['installed' => ['test-vendor/clean-ext']]);

		expect($data['installed'])->toBeNull();
	});
});
