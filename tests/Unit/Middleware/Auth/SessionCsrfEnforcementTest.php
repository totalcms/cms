<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Security\CSRF\CSRFRequestValidator;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

// Session-cookie authentication is the one auth mode CSRF can ride, so both
// auth middlewares must verify state-changing requests that were authorized by
// the session — while API-keyed requests stay exempt.
//
// A request passes on EITHER a browser-verified same origin OR a valid CSRF
// token. Origin is stamped by the browser and unforgeable from script, so it
// proves the same thing the token does without the client having to cooperate;
// the token remains the fallback for callers that send no browser headers.

beforeEach(function (): void {
	$this->session = new PhpSession();
	if (!$this->session->isStarted()) {
		$this->session->start();
	}
	$this->session->delete('csrf_token');

	$this->csrfManager = new CSRFTokenManager($this->session);
});

afterEach(function (): void {
	$this->csrfManager->clearToken();
});

function csrfTestConfig(): Config
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth    = ['enable' => true, 'collection' => 'auth'];
	$config->session = ['gc_maxlifetime' => 1440];
	$config->domain  = 'example.com';

	return $config;
}

function csrfTestValidator(CSRFTokenManager $csrfManager): CSRFRequestValidator
{
	return csrfValidatorFor($csrfManager, 'example.com');
}

/**
 * Build a request carrying the routing attributes DualAuthMiddleware's
 * public-collection check needs (RouteContext requires them; route itself
 * is null, so the check simply returns false).
 */
function csrfTestRequest(string $method, string $uri): Psr\Http\Message\ServerRequestInterface
{
	return (new ServerRequestFactory())
		->createServerRequest($method, $uri)
		->withAttribute(Slim\Routing\RouteContext::ROUTE_PARSER, test()->createMock(Slim\Interfaces\RouteParserInterface::class))
		->withAttribute(Slim\Routing\RouteContext::ROUTING_RESULTS, test()->createMock(Slim\Routing\RoutingResults::class));
}

function csrfTestLoggerFactory(): LoggerFactory
{
	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return $loggerFactory;
}

function csrfTestAccessManager(): AccessManager
{
	$accessManager = test()->createMock(AccessManager::class);
	$accessManager->method('sessionHasUser')->willReturn(true);
	$accessManager->method('userLoggedIn')->willReturn(true);

	return $accessManager;
}

function csrfTestHandler(): RequestHandlerInterface
{
	$handler = test()->createMock(RequestHandlerInterface::class);
	$handler->method('handle')->willReturn(new Response());

	return $handler;
}

function makeDualAuthMiddleware(PhpSession $session, CSRFTokenManager $csrfManager, bool $withValidApiKey = false): DualAuthMiddleware
{
	$authenticator = test()->createMock(ApiKeyAuthenticator::class);
	$authenticator->method('hasApiKeyHeader')->willReturn($withValidApiKey);
	$authenticator->method('authenticate')->willReturn($withValidApiKey ? new ApiKeyData([
		'id'      => 'test-id',
		'name'    => 'Test Key',
		'key'     => 'tcms_validkey123',
		'created' => '2024-01-01T00:00:00+00:00',
		'scopes'  => ['methods' => [], 'paths' => []],
	]) : null);

	$editionFeatures = test()->createMock(EditionFeatureService::class);
	$editionFeatures->method('can')->willReturn(true);

	return new DualAuthMiddleware(
		$authenticator,
		new JsonRenderer(),
		new ResponseFactory(),
		$session,
		csrfTestConfig(),
		csrfTestAccessManager(),
		test()->createMock(PersistentLoginService::class),
		test()->createMock(CollectionFetcher::class),
		test()->createMock(OperationDetector::class),
		$editionFeatures,
		csrfTestValidator($csrfManager),
		test()->createMock(PropertyMetaResolver::class),
		csrfTestLoggerFactory(),
	);
}

function makeAuthMiddleware(PhpSession $session, CSRFTokenManager $csrfManager): AuthMiddleware
{
	return new AuthMiddleware(
		new ResponseFactory(),
		$session,
		csrfTestConfig(),
		csrfTestAccessManager(),
		test()->createMock(PersistentLoginService::class),
		csrfTestValidator($csrfManager),
		csrfTestLoggerFactory(),
	);
}

// ---------------------------------------------------------------------------
// DualAuthMiddleware — the /api object/property/file write surface
// ---------------------------------------------------------------------------

it('rejects a session-authenticated API POST without a CSRF token', function (): void {
	$this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/blog/post-1/image');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(403);
	expect((string)$response->getBody())->toContain('CSRF');
});

it('allows a session-authenticated API POST carrying the CSRF token header', function (): void {
	$token = $this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/blog/post-1/image')
		->withHeader('X-CSRF-Token', $token);

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

it('allows session-authenticated API GET requests without a CSRF token', function (): void {
	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('GET', '/api/collections/blog/post-1');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

it('does not require a CSRF token for API-keyed requests', function (): void {
	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager, withValidApiKey: true);
	$request    = csrfTestRequest('POST', '/api/collections/blog/post-1/image');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

// ---------------------------------------------------------------------------
// AuthMiddleware — session-only /api groups (collections, schemas, passkeys…)
// ---------------------------------------------------------------------------

it('AuthMiddleware rejects a session-authenticated POST without a CSRF token', function (): void {
	$this->csrfManager->generateToken();

	$middleware = makeAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/passkeys/register');

	$middleware->process($request, csrfTestHandler());
})->throws(Slim\Exception\HttpForbiddenException::class);

it('AuthMiddleware allows a session-authenticated POST with the CSRF token field', function (): void {
	$token = $this->csrfManager->generateToken();

	$middleware = makeAuthMiddleware($this->session, $this->csrfManager);
	$request    = (new ServerRequestFactory())
		->createServerRequest('POST', '/api/collections')
		->withParsedBody(['csrf_token' => $token]);

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

it('AuthMiddleware allows GET requests without a CSRF token', function (): void {
	$middleware = makeAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('GET', '/api/collections');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

// ---------------------------------------------------------------------------
// Same-origin acceptance — the path that lets Dropzone, Tiptap and third-party
// callers work without being taught to send a token
// ---------------------------------------------------------------------------

it('allows a same-origin API write with no CSRF token at all', function (): void {
	$this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/image/demo/image')
		->withHeader('Origin', 'https://example.com');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

it('AuthMiddleware allows a same-origin write with no CSRF token', function (): void {
	$this->csrfManager->generateToken();

	$middleware = makeAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/passkeys/register')
		->withHeader('Origin', 'https://example.com');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

it('accepts a same-origin write verified by Referer when Origin is absent', function (): void {
	$this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/image/demo/image')
		->withHeader('Referer', 'https://example.com/admin/collection/image');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(200);
});

// ---------------------------------------------------------------------------
// Cross-origin is a hard reject — a leaked token must not be usable elsewhere
// ---------------------------------------------------------------------------

it('rejects a cross-origin write even when it carries a valid CSRF token', function (): void {
	$token = $this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/blog/post-1')
		->withHeader('Origin', 'https://evil.com')
		->withHeader('X-CSRF-Token', $token);

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(403);
});

it('rejects a same-site sibling subdomain that SameSite=Lax would allow', function (): void {
	$token = $this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/blog/post-1')
		->withHeader('Origin', 'https://evil.example.com')
		->withHeader('X-CSRF-Token', $token);

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(403);
});

it('AuthMiddleware rejects a cross-origin write carrying a valid token', function (): void {
	$token = $this->csrfManager->generateToken();

	$middleware = makeAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections')
		->withHeader('Origin', 'https://evil.com')
		->withHeader('X-CSRF-Token', $token);

	$middleware->process($request, csrfTestHandler());
})->throws(Slim\Exception\HttpForbiddenException::class);

it('ignores X-Forwarded-Host when deciding what counts as same-origin', function (): void {
	$this->csrfManager->generateToken();

	$middleware = makeDualAuthMiddleware($this->session, $this->csrfManager);
	$request    = csrfTestRequest('POST', '/api/collections/blog/post-1')
		->withHeader('Origin', 'https://evil.com')
		->withHeader('X-Forwarded-Host', 'evil.com');

	$response = $middleware->process($request, csrfTestHandler());

	expect($response->getStatusCode())->toBe(403);
});
