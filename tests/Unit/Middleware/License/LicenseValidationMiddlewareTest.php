<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\License\LicenseValidationMiddleware;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

function makeLicenseMiddleware(LicenseValidator $licenseValidator, bool $setupComplete): LicenseValidationMiddleware
{
	$setupState = test()->createMock(SetupStateManager::class);
	$setupState->method('isSetupComplete')->willReturn($setupComplete);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return new LicenseValidationMiddleware(
		$licenseValidator,
		(new ReflectionClass(Config::class))->newInstanceWithoutConstructor(),
		new ResponseFactory(),
		(new ReflectionClass(RedirectRenderer::class))->newInstanceWithoutConstructor(),
		new PhpSession([]),
		$setupState,
		$loggerFactory,
	);
}

test('skips license validation entirely while setup is incomplete', function (): void {
	// The license check must never run during the setup wizard — a stale
	// "trial expired" cache from a previous install would otherwise block a
	// fresh, freshly-licensed setup on a POST step.
	$licenseValidator = test()->createMock(LicenseValidator::class);
	$licenseValidator->expects(test()->never())->method('validateLicense');

	$middleware = makeLicenseMiddleware($licenseValidator, setupComplete: false);

	$request = test()->createMock(ServerRequestInterface::class);
	$expected = (new ResponseFactory())->createResponse(200);

	$handler = test()->createMock(RequestHandlerInterface::class);
	$handler->expects(test()->once())->method('handle')->with($request)->willReturn($expected);

	$response = $middleware->process($request, $handler);

	expect($response->getStatusCode())->toBe(200);
});
