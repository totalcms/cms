<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Admin\AdminExtensionReviewAction;
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
 * A real ExtensionManager over the test fixtures, so getEnableReview()
 * answers exactly as in production. Self-contained: a global helper in
 * another test file is invisible to this one under --parallel.
 */
function makeReviewPageManager(): ExtensionManager
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

	return $manager;
}

/**
 * @param array<string,mixed>|null $rendered Receives the template data when the page renders
 */
function makeReviewPageAction(ExtensionManager $manager, ?array &$rendered = null): AdminExtensionReviewAction
{
	$routeParser = test()->createMock(RouteParserInterface::class);
	$routeParser->method('urlFor')->willReturnCallback(
		static fn (string $name, array $data = [], array $query = []): string => ($name === 'admin-extension-review'
			? '/admin/extensions/' . ($data['extension'] ?? '') . '/review'
			: '/admin/extensions') . ($query !== [] ? '?' . http_build_query($query) : ''),
	);

	$twig = test()->createMock(TwigRenderer::class);
	$twig->method('template')->willReturnCallback(
		static function (ResponseInterface $response, string $template, array $data = []) use (&$rendered): ResponseInterface {
			$rendered = $data;

			return $response->withStatus(200);
		},
	);

	return new AdminExtensionReviewAction($twig, $manager, $routeParser);
}

describe('AdminExtensionReviewAction', function (): void {
	test('nothing to review after a fresh install goes to the list with the extension highlighted', function (): void {
		$rendered = null;
		$action   = makeReviewPageAction(makeReviewPageManager(), $rendered);
		$request  = (new ServerRequestFactory())
			->createServerRequest('GET', '/admin/extensions/test-vendor/clean-ext/review')
			->withQueryParams(['installed' => '1']);

		$result = $action($request, new Response(), ['extension' => 'test-vendor/clean-ext']);

		expect($result->getStatusCode())->toBe(302)
			->and($result->getHeaderLine('Location'))->toBe('/admin/extensions?installed=' . rawurlencode('test-vendor/clean-ext'))
			->and($rendered)->toBeNull();
	});

	test('nothing to review without the install marker goes to the plain list', function (): void {
		$action  = makeReviewPageAction(makeReviewPageManager());
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/extensions/test-vendor/clean-ext/review');

		$result = $action($request, new Response(), ['extension' => 'test-vendor/clean-ext']);

		expect($result->getStatusCode())->toBe(302)
			->and($result->getHeaderLine('Location'))->toBe('/admin/extensions');
	});

	test('an unknown extension goes to the plain list', function (): void {
		$action  = makeReviewPageAction(makeReviewPageManager());
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/extensions/no/such/review');

		$result = $action($request, new Response(), ['extension' => 'no/such']);

		expect($result->getStatusCode())->toBe(302)
			->and($result->getHeaderLine('Location'))->toBe('/admin/extensions');
	});

	test('an extension with something to review renders, with its name and version', function (): void {
		$rendered = null;
		$action   = makeReviewPageAction(makeReviewPageManager(), $rendered);
		$request  = (new ServerRequestFactory())
			->createServerRequest('GET', '/admin/extensions/test-vendor/skill-ext/review')
			->withQueryParams(['installed' => '1']);

		$result = $action($request, new Response(), ['extension' => 'test-vendor/skill-ext']);

		expect($result->getStatusCode())->toBe(200)
			->and($rendered['extensionId'])->toBe('test-vendor/skill-ext')
			->and($rendered['name'])->toBe('Skill Ext (fixture)')
			->and($rendered['version'])->toBe('1.0.0')
			->and($rendered['skill'])->not->toBeNull();
	});
});
