<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Security;

use Odan\Session\PhpSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Middleware\Security\CSRFProtectionMiddleware;

/**
 * Tests for CSRFProtectionMiddleware — focusing on the no-rotation behaviour
 * and the API-key validation security fix.
 *
 * PHPSession is final and depends on PHP session state. We start a real
 * session (the bootstrap sets session.save_path to a writable tmp dir).
 */
final class CSRFProtectionMiddlewareTest extends TestCase
{
	private PhpSession $session;
	private CSRFTokenManager $csrfManager;
	private CSRFProtectionMiddleware $middleware;

	protected function setUp(): void
	{
		$this->session = new PhpSession();
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$this->csrfManager = new CSRFTokenManager($this->session);

		// Default middleware has a no-op ApiKeyAuthenticator mock (no header present)
		$noApiKey = $this->createMock(ApiKeyAuthenticator::class);
		$noApiKey->method('hasApiKeyHeader')->willReturn(false);

		$this->middleware = new CSRFProtectionMiddleware($this->csrfManager, $noApiKey);
	}

	protected function tearDown(): void
	{
		// Clear the CSRF session key so tests don't bleed into each other.
		$this->csrfManager->clearToken();
	}

	private function makeHandler(ResponseInterface $returnResponse): RequestHandlerInterface
	{
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($returnResponse);

		return $handler;
	}

	/**
	 * Build a POST request that carries the given CSRF token as a POST body field.
	 */
	private function makePostRequest(string $token): ServerRequestInterface
	{
		return (new ServerRequestFactory())
			->createServerRequest('POST', '/admin/utils')
			->withParsedBody(['csrf_token' => $token]);
	}

	// ------------------------------------------------------------------
	// Test: same token validates twice (no rotation after success)
	// ------------------------------------------------------------------

	public function testSameTokenValidatesTwiceWithoutRotation(): void
	{
		// Generate a session-bound token.
		$token = $this->csrfManager->generateToken();

		$handler  = $this->makeHandler(new Response());
		$request1 = $this->makePostRequest($token);

		// First request: should pass (token is valid).
		$result1 = $this->middleware->process($request1, $handler);
		$this->assertSame(200, $result1->getStatusCode());

		// Second request with the same token: should also pass under no-rotation.
		// Under the old rotation-on-success design, the token would have been
		// invalidated after the first request, causing the second to throw 403.
		$request2 = $this->makePostRequest($token);
		$result2  = $this->middleware->process($request2, $handler);
		$this->assertSame(200, $result2->getStatusCode());
	}

	// ------------------------------------------------------------------
	// Test: invalid token is rejected
	// ------------------------------------------------------------------

	public function testInvalidTokenIsRejected(): void
	{
		$this->csrfManager->generateToken();

		$handler = $this->makeHandler(new Response());
		$request = $this->makePostRequest('wrong-token-value');

		$this->expectException(\Slim\Exception\HttpForbiddenException::class);

		$this->middleware->process($request, $handler);
	}

	// ------------------------------------------------------------------
	// Test: GET requests bypass CSRF check
	// ------------------------------------------------------------------

	public function testGetRequestsBypassCsrfCheck(): void
	{
		$handler = $this->makeHandler(new Response());
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', '/admin/utils');

		// No token in session — should still pass (GET is not protected)
		$result = $this->middleware->process($request, $handler);
		$this->assertSame(200, $result->getStatusCode());
	}

	// ------------------------------------------------------------------
	// Test: valid API key bypasses CSRF check
	// ------------------------------------------------------------------

	public function testValidApiKeyBypassesCsrfCheck(): void
	{
		$apiKeyData = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test Key',
			'key'     => 'tcms_validkey123',
			'created' => '2024-01-01T00:00:00+00:00',
			'scopes'  => ['methods' => [], 'paths' => []],
		]);

		$authenticator = $this->createMock(ApiKeyAuthenticator::class);
		$authenticator->method('hasApiKeyHeader')->willReturn(true);
		$authenticator->method('authenticate')->willReturn($apiKeyData);

		$middleware = new CSRFProtectionMiddleware($this->csrfManager, $authenticator);

		$handler = $this->makeHandler(new Response());
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', '/api/collections/blog/objects')
			->withHeader('X-API-Key', 'tcms_validkey123');

		// No CSRF token — should still pass because API key is valid.
		$result = $middleware->process($request, $handler);
		$this->assertSame(200, $result->getStatusCode());
	}

	// ------------------------------------------------------------------
	// Test: invalid API key header is rejected with 403 (bypass closed)
	// ------------------------------------------------------------------

	public function testInvalidApiKeyHeaderIsRejectedWith403(): void
	{
		$authenticator = $this->createMock(ApiKeyAuthenticator::class);
		$authenticator->method('hasApiKeyHeader')->willReturn(true);
		$authenticator->method('authenticate')->willReturn(null); // key doesn't validate

		$middleware = new CSRFProtectionMiddleware($this->csrfManager, $authenticator);

		$handler = $this->makeHandler(new Response());
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', '/admin/collections/blog/123')
			->withHeader('X-API-Key', 'invalid-bogus-key');

		$this->expectException(\Slim\Exception\HttpForbiddenException::class);

		$middleware->process($request, $handler);
	}

	// ------------------------------------------------------------------
	// Test: invalid Bearer header is rejected with 403 (bypass closed)
	// ------------------------------------------------------------------

	public function testInvalidBearerHeaderIsRejectedWith403(): void
	{
		$authenticator = $this->createMock(ApiKeyAuthenticator::class);
		$authenticator->method('hasApiKeyHeader')->willReturn(true);
		$authenticator->method('authenticate')->willReturn(null); // Bearer token is not a stored API key

		$middleware = new CSRFProtectionMiddleware($this->csrfManager, $authenticator);

		$handler = $this->makeHandler(new Response());
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', '/admin/collections/blog/objects')
			->withHeader('Authorization', 'Bearer attacker-controlled-value');

		$this->expectException(\Slim\Exception\HttpForbiddenException::class);

		$middleware->process($request, $handler);
	}
}
