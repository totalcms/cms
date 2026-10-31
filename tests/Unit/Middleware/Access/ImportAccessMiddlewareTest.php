<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\ImportAccessMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/*
 * The whole-site importers (Total CMS 1, Alloy, WordPress, RSS) carried only
 * AuthMiddleware, so any admin login could run them: a security scan
 * (2026-10-30) reached all seven routes as a read-only `viewer` user. They now
 * need the `import` util permission that already gates the admin import pages.
 */

describe('ImportAccessMiddleware', function (): void {
	beforeEach(function (): void {
		$this->accessControl      = $this->createMock(AccessControlService::class);
		$this->session            = $this->createMock(SessionInterface::class);
		$this->jsonRenderer       = $this->createMock(JsonRenderer::class);
		$this->twigRenderer       = $this->createMock(TwigRenderer::class);
		$this->responseFactory    = $this->createMock(ResponseFactoryInterface::class);
		$this->config             = Config::init();
		$this->operationDetector  = $this->createMock(OperationDetector::class);
		$this->loggerFactory      = $this->createMock(LoggerFactory::class);
		// OAuthActivityLogger is final readonly — construct a real instance
		// backed by a NullLogger instead of createMock().
		$this->oauthActivityLogger = new OAuthActivityLogger(new NullLogger());

		$this->handler             = $this->createMock(RequestHandlerInterface::class);
		$this->passthroughResponse = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($this->passthroughResponse);

		$this->config->auth              = ['enable' => true, 'collection' => 'auth'];
		$this->config->env               = 'prod';
		$this->config->debug             = false;

		$this->forbiddenResponse = $this->createMock(ResponseInterface::class);
		$this->forbiddenResponse->method('withStatus')->willReturnSelf();
		$this->responseFactory->method('createResponse')->willReturn($this->forbiddenResponse);
		$this->jsonRenderer->method('json')->willReturn($this->forbiddenResponse);
		$this->twigRenderer->method('template')->willReturn($this->forbiddenResponse);

		$this->make = fn (): ImportAccessMiddleware => new ImportAccessMiddleware(
			new ActorResolver($this->session, $this->accessControl, $this->config),
			new ActorContext(),
			$this->jsonRenderer,
			$this->twigRenderer,
			$this->responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			new AccessDenials($this->loggerFactory, $this->oauthActivityLogger, new EventDispatcher(new NullLogger())),
		);

		$this->requestFor = function (string $method = 'POST', string $path = '/api/import/totalcms-one'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn($path);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn($method);

			return $request;
		};

		$this->bearerRequestFor = function (UserAuthority $authority, string $method = 'POST', string $path = '/api/import/totalcms-one'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn($path);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn($method);
			$request->method('getAttribute')->willReturnMap([
				['publicSubmission', null, null],
				['authMethod', null, 'oauth_bearer'],
				['oauth_user_id', '', 'auth:bearer-user'],
			]);
			$request->method('withAttribute')->willReturnSelf();

			return $request;
		};

		$importGroup                 = new AccessGroupData(['id' => 'import-group', 'permissions' => ['utils' => ['all' => false, 'allowed' => ['import']]]]);
		$otherGroup                  = new AccessGroupData(['id' => 'viewer', 'permissions' => ['utils' => ['all' => false, 'allowed' => ['cache']]]]);
		$this->grantedAuthority = new UserAuthority(isAdmin: false, groups: [$importGroup]);
		$this->deniedAuthority  = new UserAuthority(isAdmin: false, groups: [$otherGroup]);
	});

	test('session user without the import grant is denied', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'viewer-user' : null,
		);
		$this->accessControl->method('authorityOf')->with('viewer-user')->willReturn($this->deniedAuthority);

		$this->handler->expects($this->never())->method('handle');
		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);

		expect(($this->make)()->process(($this->requestFor)(), $this->handler))->toBe($this->forbiddenResponse);
	});

	test('session user with the import grant is allowed', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'importer' : null,
		);
		$this->accessControl->method('authorityOf')->with('importer')->willReturn($this->grantedAuthority);

		$this->handler->expects($this->once())->method('handle');

		expect(($this->make)()->process(($this->requestFor)('POST', '/api/import/rss-analyze'), $this->handler))->toBe($this->passthroughResponse);
	});

	test('super admin is allowed', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'admin-id' : null,
		);
		$this->accessControl->method('authorityOf')->with('admin-id')->willReturn(new UserAuthority(isAdmin: true, groups: []));

		$this->handler->expects($this->once())->method('handle');

		expect(($this->make)()->process(($this->requestFor)(), $this->handler))->toBe($this->passthroughResponse);
	});

	test('bearer token without the import grant is denied', function (): void {
		$this->accessControl->method('authorityOf')->willReturn($this->deniedAuthority);

		$this->handler->expects($this->never())->method('handle');
		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);

		expect(($this->make)()->process(($this->bearerRequestFor)($this->deniedAuthority), $this->handler))->toBe($this->forbiddenResponse);
	});
});

it('guards every whole-site importer route', function (): void {
	$routes = (string)file_get_contents(dirname(__DIR__, 4) . '/config/routes/api/import.php');

	foreach (['totalcms-one', 'alloy-analyze', 'alloy', 'wordpress-analyze', 'wordpress', 'rss-analyze', 'rss'] as $name) {
		expect($routes)->toMatch("/->setName\\('import-{$name}'\\)->add\\(ImportAccessMiddleware::class\\)/");
	}
});
