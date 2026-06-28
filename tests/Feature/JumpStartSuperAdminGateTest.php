<?php

/**
 * JumpStart Super-Admin Gate Test.
 *
 * Verifies the three HTTP entry-points for JumpStart are super-admin-only:
 *
 *   1. GET  /api/export/jumpstart       — AdminOnlyMiddleware on route
 *   2. POST /api/import/jumpstart       — AdminOnlyMiddleware on route
 *   3. GET  /admin/utils/jumpstart      — canAccessUtils() refuses non-admins
 *
 * The test environment has auth.enable = false (local.test.php), which means
 * full-app dispatches bypass all auth middleware. Route-level tests therefore
 * directly instantiate AdminOnlyMiddleware and exercise it with mocked
 * session/userValidation — the same approach used by
 * SystemCollectionGuardMiddlewareTest and BaseAccessMiddlewareTest.
 *
 * CLI paths (JumpStartExportCommand, JumpStartImportCommand) and the
 * StarterService call the service layer directly and are not affected.
 */

declare(strict_types=1);

namespace Tests\Feature;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

describe('JumpStart AdminOnlyMiddleware gate (export + import routes)', function (): void {
	beforeEach(function (): void {
		$this->session           = $this->createMock(SessionInterface::class);
		$this->userValidation    = $this->createMock(UserValidationService::class);
		$this->accessControl     = $this->createMock(AccessControlService::class);
		$this->jsonRenderer      = $this->createMock(JsonRenderer::class);
		$this->twigRenderer      = $this->createMock(TwigRenderer::class);
		$this->responseFactory   = $this->createMock(ResponseFactoryInterface::class);
		$this->operationDetector = $this->createMock(OperationDetector::class);
		$this->loggerFactory     = $this->createMock(LoggerFactory::class);

		$this->config        = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth  = ['enable' => true];
		$this->config->env   = 'prod';
		$this->config->debug = false;

		$this->handler     = $this->createMock(RequestHandlerInterface::class);
		$this->passthrough = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($this->passthrough);

		$this->forbidden = $this->createMock(ResponseInterface::class);
		$this->forbidden->method('withStatus')->willReturnSelf();
		$this->responseFactory->method('createResponse')->willReturn($this->forbidden);
		$this->jsonRenderer->method('json')->willReturn($this->forbidden);
		$this->twigRenderer->method('template')->willReturn($this->forbidden);

		// A minimal API-path request (no /admin/ prefix → JSON 403).
		$this->apiRequest = function (?string $authMethod = null): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn('/api/export/jumpstart');

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn('GET');
			$request->method('getAttribute')->willReturnMap([
				['publicSubmission', null, null],
				['authMethod', null, $authMethod],
			]);

			return $request;
		};

		$this->makeMiddleware = function (): AdminOnlyMiddleware {
			return new AdminOnlyMiddleware(
				$this->userValidation,
				$this->accessControl,
				$this->session,
				$this->jsonRenderer,
				$this->twigRenderer,
				$this->responseFactory,
				$this->config,
				$this->operationDetector,
				$this->loggerFactory,
			);
		};
	});

	it('returns 403 when no session user is present (unauthenticated)', function (): void {
		$this->session->method('get')->with(SessionKeys::AUTH_USER)->willReturn(null);

		$result = ($this->makeMiddleware)()->process(($this->apiRequest)(), $this->handler);

		expect($result)->toBe($this->forbidden);
		$this->handler->expects($this->never())->method('handle');
	});

	it('returns 403 for a logged-in non-super-admin', function (): void {
		$this->session->method('get')
			->willReturnCallback(static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'editor-user-test-com' : null);
		$this->userValidation->method('isSuperAdmin')->with('editor-user-test-com')->willReturn(false);
		// AdminOnlyMiddleware::checkPermission always returns false for non-admins.
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$result = ($this->makeMiddleware)()->process(($this->apiRequest)(), $this->handler);

		expect($result)->toBe($this->forbidden);
	});

	it('passes through for a super-admin session', function (): void {
		$this->session->method('get')
			->willReturnCallback(static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'admin-user-test-com' : null);
		$this->userValidation->method('isSuperAdmin')->with('admin-user-test-com')->willReturn(true);

		$result = ($this->makeMiddleware)()->process(($this->apiRequest)(), $this->handler);

		expect($result)->toBe($this->passthrough);
	});

	it('passes through for an API-key caller (CLI / push-pull)', function (): void {
		// API-key callers have authMethod = 'apikey' — BaseAccessMiddleware bypasses
		// all group checks so CLI push/pull continues to work.
		$result = ($this->makeMiddleware)()->process(($this->apiRequest)('apikey'), $this->handler);

		expect($result)->toBe($this->passthrough);
		$this->userValidation->expects($this->never())->method('isSuperAdmin');
	});

	it('passes through when auth is disabled globally', function (): void {
		$this->config->auth = ['enable' => false];

		$result = ($this->makeMiddleware)()->process(($this->apiRequest)(), $this->handler);

		expect($result)->toBe($this->passthrough);
	});
});

describe('JumpStart utils admin-page gate (canAccessUtils)', function (): void {
	beforeEach(function (): void {
		$this->accessControl = bootstrap()->getContainer()->get(AccessControlService::class);
	});

	it('allows a super-admin to access the jumpstart utils page', function (): void {
		// 'admin' is in the admin group → isSuperAdmin() = true.
		expect($this->accessControl->canAccessUtils('admin', 'jumpstart'))->toBeTrue();
	});

	it('denies a non-super-admin even when their group lists jumpstart in utils.allowed', function (): void {
		// 'editor-user-test-com' is in the editor group, which has jumpstart in
		// utils.allowed — but SUPER_ADMIN_ONLY_UTILS blocks the group bypass.
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'jumpstart'))->toBeFalse();
	});

	it('denies a non-super-admin with no utils permissions', function (): void {
		expect($this->accessControl->canAccessUtils('blogger-user-test-com', 'jumpstart'))->toBeFalse();
	});
});
