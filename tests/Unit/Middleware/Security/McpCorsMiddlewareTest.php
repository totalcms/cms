<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Security;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Middleware\Security\McpCorsMiddleware;
use TotalCMS\Support\Config;

final class McpCorsMiddlewareTest extends TestCase
{
	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * @param list<mixed> $allowedOrigins
	 */
	private function middleware(array $allowedOrigins = []): McpCorsMiddleware
	{
		$config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->mcp = ['allowedOrigins' => $allowedOrigins];

		return new McpCorsMiddleware($config);
	}

	private function request(string $method, ?string $origin = null): ServerRequestInterface
	{
		$req = (new Psr17Factory())->createServerRequest($method, '/mcp');
		if ($origin !== null) {
			$req = $req->withHeader('Origin', $origin);
		}

		return $req;
	}

	private function passthroughHandler(int $status = 200): RequestHandlerInterface
	{
		return new class($status) implements RequestHandlerInterface {
			public function __construct(private readonly int $status)
			{
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return new Response($this->status);
			}
		};
	}

	// ── Open by default (blank == *) ─────────────────────────────────────────

	public function testEmptyAllowlistAllowsAnyOrigin(): void
	{
		// A blank allowlist is treated the same as `*`: the request origin is
		// echoed so browser-based AI clients work out of the box.
		$response = $this->middleware([])->process(
			$this->request('GET', 'https://example.com'),
			$this->passthroughHandler(),
		);

		$this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('Origin', $response->getHeaderLine('Vary'));
	}

	public function testEmptyAllowlistWithoutOriginEmitsWildcardLiteral(): void
	{
		// Blank + no Origin header → the `*` literal, mirroring explicit `*`.
		$response = $this->middleware([])->process(
			$this->request('GET'),
			$this->passthroughHandler(),
		);

		$this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	public function testRequestWithoutOriginPassesThroughCleanly(): void
	{
		$response = $this->middleware(['https://example.com'])->process(
			$this->request('GET'),
			$this->passthroughHandler(),
		);

		$this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	// ── Explicit allowlist ───────────────────────────────────────────────────

	public function testAllowedOriginIsEchoed(): void
	{
		$response = $this->middleware(['https://example.com', 'https://app.example.com'])
			->process($this->request('GET', 'https://example.com'), $this->passthroughHandler());

		$this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('Origin', $response->getHeaderLine('Vary'));
	}

	public function testDisallowedOriginEmitsNoHeader(): void
	{
		$response = $this->middleware(['https://example.com'])
			->process($this->request('GET', 'https://evil.example'), $this->passthroughHandler());

		$this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	public function testOriginMatchIsCaseAndExactSubdomainSensitive(): void
	{
		$response = $this->middleware(['https://example.com'])
			->process($this->request('GET', 'https://app.example.com'), $this->passthroughHandler());

		$this->assertSame(
			'',
			$response->getHeaderLine('Access-Control-Allow-Origin'),
			'Subdomain should not match the apex entry — operators must list each subdomain explicitly.'
		);
	}

	// ── Wildcard ─────────────────────────────────────────────────────────────

	public function testWildcardEchoesIncomingOrigin(): void
	{
		$response = $this->middleware(['*'])
			->process($this->request('GET', 'https://random.test'), $this->passthroughHandler());

		$this->assertSame('https://random.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	public function testWildcardWithoutOriginEmitsWildcardLiteral(): void
	{
		$response = $this->middleware(['*'])
			->process($this->request('GET'), $this->passthroughHandler());

		$this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	// ── Preflight ────────────────────────────────────────────────────────────

	public function testPreflightReturns204WithCorsHandshakeHeaders(): void
	{
		$response = $this->middleware(['https://example.com'])
			->process($this->request('OPTIONS', 'https://example.com'), $this->passthroughHandler(500));

		// The handler is intentionally never invoked on OPTIONS, so the 500
		// it would return is replaced by the middleware's own 204.
		$this->assertSame(204, $response->getStatusCode());
		$this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('POST, GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
		$this->assertStringContainsString('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Allow-Headers'));
		$this->assertStringContainsString('X-API-Key', $response->getHeaderLine('Access-Control-Allow-Headers'));
		$this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
	}

	public function testPreflightFromDisallowedOriginStillReturns204ButNoAllowOriginHeader(): void
	{
		// Preflight short-circuits to 204 regardless — the browser inspects
		// the Access-Control-Allow-Origin to decide whether to proceed. No
		// header means "not allowed", which is the right signal.
		$response = $this->middleware(['https://example.com'])
			->process($this->request('OPTIONS', 'https://evil.example'), $this->passthroughHandler());

		$this->assertSame(204, $response->getStatusCode());
		$this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('POST, GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
	}

	// ── Hygiene on the allowlist ─────────────────────────────────────────────

	public function testWhitespaceAndDuplicatesInConfigAreTolerated(): void
	{
		$response = $this->middleware(['  https://example.com  ', 'https://example.com', '', '  '])
			->process($this->request('GET', 'https://example.com'), $this->passthroughHandler());

		$this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	public function testNullOriginIsNeverEchoedInOpenMode(): void
	{
		// The literal "null" Origin is sent by sandboxed iframes / data: URIs.
		// An open allowlist must not echo it back — doing so grants cross-origin
		// access to those opaque contexts.
		$response = $this->middleware([])->process(
			$this->request('GET', 'null'),
			$this->passthroughHandler(),
		);

		$this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	public function testNullOriginIsNeverEchoedWithWildcardAllowlist(): void
	{
		$response = $this->middleware(['*'])->process(
			$this->request('GET', 'null'),
			$this->passthroughHandler(),
		);

		$this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}

	public function testNonArrayConfigNormalizesToOpenAllowlist(): void
	{
		// A non-array config normalizes to an empty allowlist, which now means
		// "allow any origin" — so the request origin is echoed.
		$config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->mcp = ['allowedOrigins' => 'not-an-array'];

		$middleware = new McpCorsMiddleware($config);
		$response   = $middleware->process(
			$this->request('GET', 'https://example.com'),
			$this->passthroughHandler(),
		);

		$this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}
}
