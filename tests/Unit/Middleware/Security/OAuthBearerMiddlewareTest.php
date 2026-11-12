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
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;
use TotalCMS\Domain\OAuth\Service\OAuthServerFactory;
use TotalCMS\Middleware\Security\OAuthBearerMiddleware;

final class OAuthBearerMiddlewareTest extends TestCase
{
	private string $stateDir = '';

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * An OAuthRevocationList over a throwaway state store, with every jti
	 * mapped to true already revoked.
	 *
	 * @param array<string,bool> $revokedJtis  map of jti → isRevoked
	 */
	private function revocationList(array $revokedJtis = []): OAuthRevocationList
	{
		if ($this->stateDir === '') {
			$this->stateDir = sys_get_temp_dir() . '/oauth-bearer-test-' . uniqid('', true);
		}
		$list = new OAuthRevocationList(new OAuthStateStore($this->stateDir), 3600);
		foreach ($revokedJtis as $jti => $revoked) {
			if ($revoked) {
				$list->revoke($jti);
			}
		}

		return $list;
	}

	protected function tearDown(): void
	{
		if ($this->stateDir !== '') {
			recursiveDelete($this->stateDir);
		}
	}

	/**
	 * Wrap a ResourceServer mock in a stub OAuthServerFactory so the
	 * middleware can lazily resolve it.
	 */
	private function factoryReturning(ResourceServer $resourceServer): OAuthServerFactory
	{
		$factory = $this->createMock(OAuthServerFactory::class);
		$factory->method('buildResourceServer')->willReturn($resourceServer);

		return $factory;
	}

	private function middleware(
		ResourceServer $resourceServer,
		OAuthRevocationList $revocationList,
	): OAuthBearerMiddleware {
		return new OAuthBearerMiddleware($this->factoryReturning($resourceServer), $revocationList, $this->clients());
	}

	/**
	 * A client store holding the listed client ids.
	 *
	 * @param list<string> $ids
	 */
	private function clients(array $ids = ['client-1']): OAuthClientRepository
	{
		if ($this->stateDir === '') {
			$this->stateDir = sys_get_temp_dir() . '/oauth-bearer-test-' . uniqid('', true);
		}
		$clients = new OAuthClientRepository(...jsonStoreArgs($this->stateDir . '/oauth-clients.json'));
		foreach ($ids as $id) {
			$clients->save(new OAuthClientData(
				id: $id,
				name: 'Claude',
				secretHash: 'hash',
				redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
				scopes: ['mcp:tools'],
				isDynamic: true,
				isConfidential: true,
				createdAt: '2026-11-11T00:00:00Z',
				createdBy: 'dynamic',
			));
		}

		return $clients;
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
		$factory         = new Psr17Factory();
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

	// ── A token whose client was deleted → 401 ───────────────────────────────

	public function testTokenOfADeletedClientReturns401(): void
	{
		// Deleting a client drops its grants; its access token, a JWT, must
		// stop working then too, not when it expires an hour later.
		$factory         = new Psr17Factory();
		$incomingRequest = $factory->createServerRequest('POST', '/mcp')
			->withHeader('Authorization', 'Bearer valid.jwt.token');

		$resourceServer = $this->createMock(ResourceServer::class);
		$resourceServer->method('validateAuthenticatedRequest')->willReturn($incomingRequest
			->withAttribute('oauth_access_token_id', 'jti-live')
			->withAttribute('oauth_client_id', 'deleted-client')
			->withAttribute('oauth_scopes', ['mcp:tools']));

		$handler    = $this->capturingHandler();
		$middleware = new OAuthBearerMiddleware($this->factoryReturning($resourceServer), $this->revocationList(), $this->clients(['client-1']));
		$response   = $middleware->process($incomingRequest, $handler);

		$this->assertSame(401, $response->getStatusCode());
		$this->assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
		$this->assertStringContainsString('client removed', $response->getHeaderLine('WWW-Authenticate'));
		$this->assertNull($handler->received);
	}

	// ── Test 5: Bearer present but keys missing → 401, no 500 ────────────────

	public function testMissingKeysReturns401NotServerError(): void
	{
		// Simulate the keys-not-generated case: the factory throws when asked
		// to build the ResourceServer (CryptKey: "Invalid key supplied"). The
		// middleware must NOT bubble this as a 500 — it should return a 401.
		$factory = $this->createMock(OAuthServerFactory::class);
		$factory->expects($this->once())
			->method('buildResourceServer')
			->willThrowException(new \RuntimeException('Invalid key supplied'));

		$handler    = $this->capturingHandler();
		$middleware = new OAuthBearerMiddleware($factory, $this->revocationList(), $this->clients());
		$response   = $middleware->process($this->request('GET', 'Bearer any.jwt.token'), $handler);

		$this->assertSame(401, $response->getStatusCode());
		$this->assertStringContainsString('Bearer realm="T3"', $response->getHeaderLine('WWW-Authenticate'));
		$this->assertStringContainsString('invalid_token', $response->getHeaderLine('WWW-Authenticate'));
		$this->assertNull($handler->received);
	}

	// ── Test 6: Valid JWT, revoked → 401 with "token revoked" ────────────────

	public function testRevokedJwtReturns401WithTokenRevokedDescription(): void
	{
		$factory         = new Psr17Factory();
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
