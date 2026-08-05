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
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\CacheAccessMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/*
 * /api/cache previously had NO access-group middleware — only AuthMiddleware
 * (any authenticated caller). A security review (Task 8 fix round, 2026-08)
 * proved live that a non-admin OAuth Bearer token with cms:admin could
 * DELETE /api/cache, POST /api/cache/devmode, and DELETE /api/cache/watermarks,
 * all 200 — and the same gap existed for session-authenticated non-admin
 * dashboard users, since the cache-manager admin PAGE's form posts straight
 * to this route. These tests pin the fix for BOTH auth paths.
 */

describe('CacheAccessMiddleware', function (): void {
	beforeEach(function (): void {
		$this->userValidation    = $this->createMock(UserValidationService::class);
		$this->accessControl     = $this->createMock(AccessControlService::class);
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

		$this->config->auth             = ['enable' => true, 'collection' => 'auth'];
		$this->config->env               = 'prod';
		$this->config->debug             = false;

		$this->forbiddenResponse = $this->createMock(ResponseInterface::class);
		$this->forbiddenResponse->method('withStatus')->willReturnSelf();
		$this->responseFactory->method('createResponse')->willReturn($this->forbiddenResponse);
		$this->jsonRenderer->method('json')->willReturn($this->forbiddenResponse);
		$this->twigRenderer->method('template')->willReturn($this->forbiddenResponse);

		$this->make = fn (): CacheAccessMiddleware => new CacheAccessMiddleware(
			$this->userValidation,
			$this->accessControl,
			$this->session,
			$this->jsonRenderer,
			$this->twigRenderer,
			$this->responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			$this->oauthActivityLogger,
		);

		$this->requestFor = function (string $method = 'DELETE', string $path = '/api/cache'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn($path);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn($method);

			return $request;
		};

		// BaseAccessMiddleware::process() resolves the authority via
		// authorityFor(), then calls $request->withAttribute('accessAuthority', $authority)
		// before invoking checkPermission() with the REASSIGNED $request. A
		// bare mock's withAttribute() doesn't actually persist that for a
		// later getAttribute() call, so $authority is pre-wired into the
		// getAttribute() map directly — it must be the exact same instance
		// the test also configures accessControl->authorityFor() to return.
		$this->bearerRequestFor = function (UserAuthority $authority, string $method = 'DELETE', string $path = '/api/cache'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn($path);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn($method);
			$request->method('getAttribute')->willReturnMap([
				['publicSubmission', null, null],
				['authMethod', null, 'oauth_bearer'],
				['oauth_user_id', '', 'auth:bearer-user'],
				['accessAuthority', null, $authority],
			]);
			$request->method('withAttribute')->willReturnSelf();

			return $request;
		};

		$cacheGroup   = new AccessGroupData(['id' => 'cache-group', 'permissions' => ['utils' => ['all' => false, 'allowed' => ['cache']]]]);
		$otherGroup   = new AccessGroupData(['id' => 'other-group', 'permissions' => ['utils' => ['all' => false, 'allowed' => ['jumpstart']]]]);
		$this->cacheGrantedAuthority = new UserAuthority(isAdmin: false, groups: [$cacheGroup]);
		$this->cacheDeniedAuthority  = new UserAuthority(isAdmin: false, groups: [$otherGroup]);
	});

	// ── Bearer (OAuth) ──────────────────────────────────────────────────────

	test('bearer non-admin without a cache grant is denied', function (): void {
		$this->accessControl->method('authorityFor')->willReturn($this->cacheDeniedAuthority);

		$this->handler->expects($this->never())->method('handle');
		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);

		$response = ($this->make)()->process(($this->bearerRequestFor)($this->cacheDeniedAuthority), $this->handler);

		expect($response)->toBe($this->forbiddenResponse);
	});

	test('bearer non-admin WITH a cache grant is allowed', function (): void {
		$this->accessControl->method('authorityFor')->willReturn($this->cacheGrantedAuthority);

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->bearerRequestFor)($this->cacheGrantedAuthority), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	test('bearer admin authority bypasses the check entirely', function (): void {
		$adminAuthority = new UserAuthority(isAdmin: true, groups: []);
		$this->accessControl->method('authorityFor')->willReturn($adminAuthority);

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->bearerRequestFor)($adminAuthority), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	// ── Session (admin UI) ──────────────────────────────────────────────────

	test('session non-admin without a cache grant is denied', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'regular-user' : null,
		);
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->accessControl->method('canAccessUtils')->with('regular-user', 'cache')->willReturn(false);

		$this->handler->expects($this->never())->method('handle');
		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);

		$response = ($this->make)()->process(($this->requestFor)(), $this->handler);

		expect($response)->toBe($this->forbiddenResponse);
	});

	test('session non-admin WITH a cache grant is allowed', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'regular-user' : null,
		);
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->accessControl->method('canAccessUtils')->with('regular-user', 'cache')->willReturn(true);

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->requestFor)(), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	test('session super admin bypasses the check entirely', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'admin-id' : null,
		);
		$this->userValidation->method('isSuperAdmin')->with('admin-id')->willReturn(true);
		$this->accessControl->expects($this->never())->method('canAccessUtils');

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->requestFor)(), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	// ── operation-detection bypass ──────────────────────────────────────────

	test('operation detection is never consulted — these routes are not CRUD-shaped', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'regular-user' : null,
		);
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->accessControl->method('canAccessUtils')->willReturn(true);
		$this->operationDetector->expects($this->never())->method('detectOperation');

		($this->make)()->process(($this->requestFor)('POST', '/api/cache/devmode'), $this->handler);
	});
});
