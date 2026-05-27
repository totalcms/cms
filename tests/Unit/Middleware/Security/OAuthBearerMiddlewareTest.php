<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Security;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Middleware\Security\OAuthBearerMiddleware;

final class OAuthBearerMiddlewareTest extends TestCase
{
	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Build an OAuthRevocationList that always returns a fixed value without
	 * touching the cache. OAuthRevocationList is `final` so we cannot use
	 * createMock — instead we build it from a fake CacheManager.
	 *
	 * @param array<string,bool> $revokedJtis  map of jti → isRevoked
	 */
	private function revocationList(array $revokedJtis = []): OAuthRevocationList
	{
		$cache = new class($revokedJtis) extends CacheManager {
			/** @param array<string,bool> $revokedJtis */
			public function __construct(private readonly array $revokedJtis)
			{
				// Skip real CacheManager constructor — no actual cache needed.
			}

			public function getComputedData(string $key): mixed
			{
				// The revocation list prefixes with 'oauth.revoked.jti.'
				$jti = str_replace('oauth.revoked.jti.', '', $key);

				return $this->revokedJtis[$jti] ?? false;
			}

			public function storeComputedData(string $key, mixed $data, int $ttl = self::TTL_CUSTOM_SCHEMA): bool
			{
				return true; // no-op in tests
			}
		};

		return new OAuthRevocationList($cache, 3600);
	}

	private function middleware(
		ResourceServer $resourceServer,
		OAuthRevocationList $revocationList,
	): OAuthBearerMiddleware {
		return new OAuthBearerMiddleware($resourceServer, $revocationList);
	}

	/**
	 * A handler that records the request it received and returns a fixed response.
	 */
	private function capturingHandler(): object
	{
		return new class implements RequestHandlerInterface {
			public ?ServerRequestInterface $received = null;
			public ResponseInterface $response;

			public function __construct()
			{
				$this->response = new Response(200);
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				$this->received = $request;

				return $this->response;
			}
		};
	}

	private function request(string $method = 'GET', ?string $authHeader = null): ServerRequestInterface
	{
		$req = (new Psr17Factory())->createServerRequest($method, '/test');
		if ($authHeader !== null) {
			$req = $req->withHeader('Authorization', $authHeader);
		}

		return $req;
	}

	// ── Test 1: No Authorization header → pass through ───────────────────────

	public function testNoAuthorizationHeaderPassesThrough(): void
	{
		$resourceServer = $this->createMock(ResourceServer::class);
		$resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

		$handler    = $this->capturingHandler();
		$middleware = $this->middleware($resourceServer, $this->revocationList());
		$response   = $middleware->process($this->request(), $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertNotNull($handler->received);
	}

	// ── Test 2: Non-Bearer Authorization → pass through ──────────────────────

	public function testBasicAuthorizationHeaderPassesThrough(): void
	{
		$resourceServer = $this->createMock(ResourceServer::class);
		$resourceServer->expects($this->never())->method('validateAuthenticatedRequest');

		$handler    = $this->capturingHandler();
		$middleware = $this->middleware($resourceServer, $this->revocationList());
		$response   = $middleware->process($this->request('GET', 'Basic dXNlcjpwYXNz'), $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertNotNull($handler->received);
	}

	// ── Test 3: Invalid JWT → 401 with WWW-Authenticate ──────────────────────

	public function testInvalidJwtReturns401WithWwwAuthenticate(): void
	{
		$resourceServer = $this->createMock(ResourceServer::class);
		$resourceServer->expects($this->once())
			->method('validateAuthenticatedRequest')
			->willThrowException(OAuthServerException::accessDenied('Invalid token'));

		$handler    = $this->capturingHandler();
		$middleware = $this->middleware($resourceServer, $this->revocationList());
		$response   = $middleware->process($this->request('GET', 'Bearer invalid.jwt.token'), $handler);

		$this->assertSame(401, $response->getStatusCode());
		$this->assertStringContainsString('Bearer realm="T3"', $response->getHeaderLine('WWW-Authenticate'));
		$this->assertStringContainsString('invalid_token', $response->getHeaderLine('WWW-Authenticate'));
		// Handler should NOT have been called
		$this->assertNull($handler->received);
	}

	// ── Test 4: Valid JWT, not revoked → handler called with augmented request ─

	public function testValidNotRevokedJwtCallsHandlerWithAugmentedRequest(): void
	{
		$factory = new Psr17Factory();
		$incomingRequest = $factory->createServerRequest('GET', '/test')
			->withHeader('Authorization', 'Bearer valid.jwt.token');

		// League returns the request with oauth_* attributes set
		$augmentedRequest = $incomingRequest
			->withAttribute('oauth_access_token_id', 'jti-abc-123')
			->withAttribute('oauth_client_id', 'client-1')
			->withAttribute('oauth_user_id', 'user-42')
			->withAttribute('oauth_scopes', ['read', 'write']);

		$resourceServer = $this->createMock(ResourceServer::class);
		$resourceServer->expects($this->once())
			->method('validateAuthenticatedRequest')
			->with($incomingRequest)
			->willReturn($augmentedRequest);

		// jti 'jti-abc-123' is NOT in the revoked list → isRevoked returns false
		$handler    = $this->capturingHandler();
		$middleware = $this->middleware($resourceServer, $this->revocationList([]));
		$response   = $middleware->process($incomingRequest, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertNotNull($handler->received);
		$this->assertSame('jti-abc-123', $handler->received->getAttribute('oauth_access_token_id'));
		$this->assertSame('user-42', $handler->received->getAttribute('oauth_user_id'));
		$this->assertSame(['read', 'write'], $handler->received->getAttribute('oauth_scopes'));
	}

	// ── Test 5: Valid JWT, revoked → 401 with "token revoked" ────────────────

	public function testRevokedJwtReturns401WithTokenRevokedDescription(): void
	{
		$factory = new Psr17Factory();
		$incomingRequest = $factory->createServerRequest('GET', '/test')
			->withHeader('Authorization', 'Bearer revoked.jwt.token');

		$augmentedRequest = $incomingRequest
			->withAttribute('oauth_access_token_id', 'jti-revoked-456');

		$resourceServer = $this->createMock(ResourceServer::class);
		$resourceServer->expects($this->once())
			->method('validateAuthenticatedRequest')
			->willReturn($augmentedRequest);

		// jti 'jti-revoked-456' IS in the revoked list
		$handler    = $this->capturingHandler();
		$middleware = $this->middleware($resourceServer, $this->revocationList(['jti-revoked-456' => true]));
		$response   = $middleware->process($incomingRequest, $handler);

		$this->assertSame(401, $response->getStatusCode());
		$wwwAuth = $response->getHeaderLine('WWW-Authenticate');
		$this->assertStringContainsString('Bearer realm="T3"', $wwwAuth);
		$this->assertStringContainsString('error="invalid_token"', $wwwAuth);
		$this->assertStringContainsString('error_description="token revoked"', $wwwAuth);
		// Handler should NOT have been called
		$this->assertNull($handler->received);

		// Verify JSON body includes error info
		$body = (string)$response->getBody();
		$this->assertStringContainsString('invalid_token', $body);
	}
}
