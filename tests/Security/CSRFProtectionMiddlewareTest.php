<?php

namespace Tests\Security;

use Odan\Session\PhpSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Middleware\CSRFProtectionMiddleware;

/**
 * Test CSRF Protection Middleware.
 */
#[CoversClass(CSRFProtectionMiddleware::class)]
final class CSRFProtectionMiddlewareTest extends TestCase
{
	private CSRFProtectionMiddleware $middleware;
	private CSRFTokenManager $csrfManager;
	private PhpSession $session;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		parent::setUp();

		// Create PhpSession instance for testing
		$this->session = new PhpSession();
		$this->session->start();

		// Clear any existing CSRF data
		$this->session->delete('csrf_token');

		$this->csrfManager = new CSRFTokenManager($this->session);
		$this->middleware  = new CSRFProtectionMiddleware($this->csrfManager);

		// Create mocks
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->handler  = $this->createMock(RequestHandlerInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		// Default handler behavior
		$this->handler
			->method('handle')
			->willReturn($this->response);
	}

	protected function tearDown(): void
	{
		// Clean up session data
		if ($this->session->isStarted()) {
			$this->session->delete('csrf_token');
			$this->session->destroy();
		}

		parent::tearDown();
	}

	public function testAllowsGetRequests(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getUri')->willReturn($uri);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testAllowsHeadRequests(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('HEAD');
		$this->request->method('getUri')->willReturn($uri);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testAllowsOptionsRequests(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('OPTIONS');
		$this->request->method('getUri')->willReturn($uri);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testBlocksPostRequestWithoutToken(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);
		$this->expectExceptionMessage('CSRF token validation failed');

		$this->middleware->process($this->request, $this->handler);
	}

	public function testAllowsPostRequestWithValidToken(): void
	{
		$token = $this->csrfManager->generateToken();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn(['csrf_token' => $token]);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testBlocksPostRequestWithInvalidToken(): void
	{
		$this->csrfManager->generateToken(); // Generate valid token but don't use it

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn(['csrf_token' => 'invalid_token']);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);

		$this->middleware->process($this->request, $this->handler);
	}

	public function testAllowsRequestWithValidHeaderToken(): void
	{
		$token = $this->csrfManager->generateToken();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn(['X-CSRF-Token' => [$token]]);
		$this->request->method('getQueryParams')->willReturn([]);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testBlocksPutRequestWithoutToken(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('PUT');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);

		$this->middleware->process($this->request, $this->handler);
	}

	public function testBlocksDeleteRequestWithoutToken(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('DELETE');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);

		$this->middleware->process($this->request, $this->handler);
	}

	public function testBlocksPatchRequestWithoutToken(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('PATCH');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);

		$this->middleware->process($this->request, $this->handler);
	}

	public function testRequiresProtectionMethod(): void
	{
		$this->assertTrue($this->middleware->requiresProtection('POST'));
		$this->assertTrue($this->middleware->requiresProtection('PUT'));
		$this->assertTrue($this->middleware->requiresProtection('DELETE'));
		$this->assertTrue($this->middleware->requiresProtection('PATCH'));
		$this->assertTrue($this->middleware->requiresProtection('post')); // case insensitive

		$this->assertFalse($this->middleware->requiresProtection('GET'));
		$this->assertFalse($this->middleware->requiresProtection('HEAD'));
		$this->assertFalse($this->middleware->requiresProtection('OPTIONS'));
	}

	public function testHandlesObjectParsedBody(): void
	{
		$token = $this->csrfManager->generateToken();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		// Create an object with csrf_token property
		$parsedBody             = new \stdClass();
		$parsedBody->csrf_token = $token;

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn($parsedBody);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testHandlesNullParsedBody(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn(null);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);

		$this->middleware->process($this->request, $this->handler);
	}

	public function testHandlesMultipleHeaderValues(): void
	{
		$token = $this->csrfManager->generateToken();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([
			'X-CSRF-Token' => [$token, 'other_value'],
			'Content-Type' => ['application/json'],
		]);
		$this->request->method('getQueryParams')->willReturn([]);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	public function testHandlesEmptyHeaders(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([
			'X-CSRF-Token' => [], // Empty header array
		]);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->expectException(HttpForbiddenException::class);

		$this->middleware->process($this->request, $this->handler);
	}

	/**
	 * Test that query parameter tokens work as fallback.
	 */
	public function testAllowsRequestWithValidQueryToken(): void
	{
		$token = $this->csrfManager->generateToken();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn([]);
		$this->request->method('getHeaders')->willReturn([]);
		$this->request->method('getQueryParams')->willReturn(['csrf_token' => $token]);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}

	/**
	 * Test POST data takes priority over headers.
	 */
	public function testPostDataTakesPriorityOverHeaders(): void
	{
		$validToken   = $this->csrfManager->generateToken();
		$invalidToken = 'invalid_token';

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/test');

		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getParsedBody')->willReturn(['csrf_token' => $validToken]);
		$this->request->method('getHeaders')->willReturn(['X-CSRF-Token' => [$invalidToken]]);
		$this->request->method('getQueryParams')->willReturn([]);

		$response = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $response);
	}
}
