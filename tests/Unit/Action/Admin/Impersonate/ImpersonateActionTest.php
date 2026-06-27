<?php

declare(strict_types=1);

use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Admin\Impersonate\ImpersonateStartAction;
use TotalCMS\Action\Admin\Impersonate\ImpersonateStopAction;
use TotalCMS\Domain\Auth\Exception\ImpersonationException;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Support\Config;

function makeRouteParser(string $adminUrl = '/admin'): RouteParserInterface
{
	$parser = test()->createMock(RouteParserInterface::class);
	$parser->method('urlFor')->willReturnCallback(
		static function (string $name) use ($adminUrl): string {
			return $name === 'admin-index' ? $adminUrl : $adminUrl;
		},
	);

	return $parser;
}

function makeConfig(string $authCollection = 'auth'): Config
{
	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['collection' => $authCollection];

	return $config;
}

describe('ImpersonateStartAction', function (): void {
	test('start action delegates to the service and redirects for member target', function (): void {
		$svc = test()->createMock(ImpersonationServiceInterface::class);
		$svc->expects(test()->once())->method('start')->with('members', 'jane');

		$action  = new ImpersonateStartAction($svc, makeRouteParser(), makeConfig());
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/impersonate/members/jane');

		$response = ($action)($request, new Response(), ['collection' => 'members', 'userId' => 'jane']);

		expect($response->getStatusCode())->toBeIn([302, 303]);
		// Member target redirects to front-end home
		expect($response->getHeaderLine('Location'))->toBe('/');
	});

	test('start action redirects to admin dashboard for operator target', function (): void {
		$svc = test()->createMock(ImpersonationServiceInterface::class);
		$svc->expects(test()->once())->method('start')->with('auth', 'bob');

		$action  = new ImpersonateStartAction($svc, makeRouteParser('/admin'), makeConfig('auth'));
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/impersonate/auth/bob');

		$response = ($action)($request, new Response(), ['collection' => 'auth', 'userId' => 'bob']);

		expect($response->getStatusCode())->toBeIn([302, 303]);
		expect($response->getHeaderLine('Location'))->toBe('/admin');
	});

	test('start action redirects back with error on ImpersonationException', function (): void {
		$svc = test()->createMock(ImpersonationServiceInterface::class);
		$svc->method('start')->willThrowException(new ImpersonationException('Only super-admins may impersonate.'));

		$action  = new ImpersonateStartAction($svc, makeRouteParser(), makeConfig());
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', '/admin/impersonate/members/jane')
			->withHeader('Referer', '/admin/collections/auth');

		$response = ($action)($request, new Response(), ['collection' => 'members', 'userId' => 'jane']);

		expect($response->getStatusCode())->toBeIn([302, 303]);
		expect($response->getHeaderLine('Location'))->toContain('error=');
		expect($response->getHeaderLine('Location'))->toContain('Only+super-admins');
	});

	test('start action falls back to admin-index when no Referer on exception', function (): void {
		$svc = test()->createMock(ImpersonationServiceInterface::class);
		$svc->method('start')->willThrowException(new ImpersonationException('Guard failure.'));

		$action   = new ImpersonateStartAction($svc, makeRouteParser('/admin'), makeConfig());
		$request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/impersonate/members/jane');
		$response = ($action)($request, new Response(), ['collection' => 'members', 'userId' => 'jane']);

		expect($response->getStatusCode())->toBeIn([302, 303]);
		expect($response->getHeaderLine('Location'))->toContain('/admin');
	});
});

describe('ImpersonateStopAction', function (): void {
	test('stop action delegates to the service and redirects to admin dashboard', function (): void {
		$svc = test()->createMock(ImpersonationServiceInterface::class);
		$svc->expects(test()->once())->method('stop');

		$action  = new ImpersonateStopAction($svc, makeRouteParser('/admin'));
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/impersonate/stop');

		$response = ($action)($request, new Response(), []);

		expect($response->getStatusCode())->toBeIn([302, 303]);
		expect($response->getHeaderLine('Location'))->toBe('/admin');
	});
});
