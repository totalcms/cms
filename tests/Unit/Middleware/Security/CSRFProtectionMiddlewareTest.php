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
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Middleware\Security\CSRFProtectionMiddleware;

/**
 * Tests for CSRFProtectionMiddleware — focusing on the no-rotation behaviour.
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
		$this->middleware  = new CSRFProtectionMiddleware($this->csrfManager);
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
	// Test: Bearer token bypasses CSRF check
	// ------------------------------------------------------------------

	public function testBearerAuthBypassesCsrfCheck(): void
	{
		$handler = $this->makeHandler(new Response());
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', '/api/collections/blog/objects')
			->withHeader('Authorization', 'Bearer some-jwt-token');

		// No CSRF token — should still pass because Bearer auth bypasses CSRF.
		$result = $this->middleware->process($request, $handler);
		$this->assertSame(200, $result->getStatusCode());
	}
}
