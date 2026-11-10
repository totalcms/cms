<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Bundled\Protect\Extension;
use TotalCMS\Bundled\Protect\ProtectMiddleware;
use TotalCMS\Domain\Builder\Exception\PageResponseException;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;
use Twig\TwigFunction;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/protect/Extension.php';

/**
 * protect_page() as the extension registers it. ExtensionGuard turns anything
 * but a PageResponseException into an empty string, and the page then renders,
 * so every way out of the function has to be a response or a decision to let
 * the visitor through.
 */
function protectPageFunction(ContainerInterface $container): TwigFunction
{
	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);

	$context = new ExtensionContext(
		ExtensionManifest::fromArray(['id' => 'totalcms/protect', 'name' => 'Protect', 'version' => '1.0.0']),
		'/tmp',
		$container,
		new ExtensionSettingsManager($storage),
		new NullLogger(),
	);

	(new Extension())->register($context);

	$functions = array_filter($context->getRegisteredTwigFunctions(), fn (TwigFunction $fn): bool => $fn->getName() === 'protect_page');

	return array_values($functions)[0];
}

/** A container where only the middleware is real: the rest is what register() needs for storage. */
function protectContainer(Closure $middleware): ContainerInterface
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = '/tmp';

	$container = test()->createMock(ContainerInterface::class);
	$container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
		ProtectMiddleware::class       => $middleware(),
		StorageAdapterInterface::class => test()->createMock(StorageAdapterInterface::class),
		Config::class                  => $config,
	});

	return $container;
}

test('the function answers with the prompt from the real middleware', function (): void {
	$middleware = new ProtectMiddleware(str_repeat('a', 64), '8675');

	try {
		(protectPageFunction(protectContainer(fn (): ProtectMiddleware => $middleware))->getCallable())();
		test()->fail('The page was let through');
	} catch (PageResponseException $answer) {
		expect($answer->response->getStatusCode())->toBe(403)
			->and((string)$answer->response->getBody())->toContain('name="passcode"');
	}
});

test('a gate that cannot run withholds the page instead of rendering it', function (): void {
	$crash = fn () => throw new RuntimeException('secret file unwritable');

	try {
		(protectPageFunction(protectContainer($crash))->getCallable())();
		test()->fail('The page was let through');
	} catch (PageResponseException $answer) {
		expect($answer->response->getStatusCode())->toBe(500)
			->and((string)$answer->response->getBody())->not->toContain('secret file');
	}
});
