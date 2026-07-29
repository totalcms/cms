<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Security;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcResponseWriter;
use TotalCMS\Middleware\Security\XmlRpcRateLimitMiddleware;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

/**
 * `XmlRpcRateLimitMiddleware` is the only bound on pre-auth XML parsing —
 * the endpoint sits at a path bots probe unprompted, and the parser runs
 * before any credential check — so it needs direct coverage rather than
 * relying on it happening to be exercised by the XML-RPC feature tests
 * (which run with `ratePerIp: 0` specifically to stay out of its way).
 */
final class XmlRpcRateLimitMiddlewareTest extends TestCase
{
	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * `Config` has a huge constructor, so — same pattern used elsewhere in this
	 * suite for Config doubles — build one via reflection and set only the
	 * `xmlrpc` array this middleware reads.
	 */
	private function config(int $ratePerIp): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'xmlrpc'))->setValue($config, ['ratePerIp' => $ratePerIp]);

		return $config;
	}

	/**
	 * An in-memory `CacheManager` double. `CacheManager` is not `readonly`
	 * and not `final`, so a constructorless anonymous subclass overriding
	 * `getData()`/`storeData()` is enough — no real cache backend touched.
	 */
	private function workingCache(): CacheManager
	{
		return new class extends CacheManager {
			/** @var array<string,mixed> */
			private array $store = [];

			public function __construct()
			{
			}

			public function getData(string $key): mixed
			{
				return $this->store[$key] ?? null;
			}

			public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
			{
				$this->store[$key] = $data;

				return true;
			}
		};
	}

	/**
	 * Simulates every cache backend being unreachable: `getData()` always
	 * returns null (CacheManager's own real behaviour when every backend is
	 * down) and `storeData()` never actually persists anything, so a counter
	 * built on top of it can never climb — proving the limiter fails open
	 * rather than closed.
	 */
	private function brokenCache(): CacheManager
	{
		return new class extends CacheManager {
			public function __construct()
			{
			}

			public function getData(string $key): mixed
			{
				return null;
			}

			public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
			{
				return false;
			}
		};
	}

	private function middleware(CacheManager $cache, int $ratePerIp): XmlRpcRateLimitMiddleware
	{
		return new XmlRpcRateLimitMiddleware(
			$cache,
			$this->config($ratePerIp),
			new XmlRpcResponseWriter(),
			new RawRenderer(),
			new Psr17Factory(),
		);
	}

	private function request(string $ip = '203.0.113.5'): ServerRequestInterface
	{
		return (new Psr17Factory())
			->createServerRequest('POST', '/xmlrpc.php', ['REMOTE_ADDR' => $ip]);
	}

	/** A handler that counts calls and always returns a fixed 200. */
	private function countingHandler(): object
	{
		return new class implements RequestHandlerInterface {
			public int $calls = 0;

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				$this->calls++;

				return new Response(200);
			}
		};
	}

	// ── Tests ────────────────────────────────────────────────────────────────

	public function testAllowsRequestsUnderTheLimit(): void
	{
		$middleware = $this->middleware($this->workingCache(), 3);
		$handler    = $this->countingHandler();

		$response = $middleware->process($this->request(), $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(1, $handler->calls);
	}

	public function testRejectsWithAnXmlRpcFaultBodyOnceOverTheLimit(): void
	{
		$cache      = $this->workingCache();
		$middleware = $this->middleware($cache, 2);
		$handler    = $this->countingHandler();

		// Two calls consume the limit (2); the third must be rejected.
		$middleware->process($this->request(), $handler);
		$middleware->process($this->request(), $handler);
		$response = $middleware->process($this->request(), $handler);

		$this->assertSame(429, $response->getStatusCode());
		$this->assertSame(2, $handler->calls, 'the third call must never reach the handler');
		$this->assertStringContainsString('text/xml', $response->getHeaderLine('Content-Type'));

		$body = (string)$response->getBody();
		// XML-RPC fault, not a JSON error body — the caller only parses XML.
		$this->assertStringContainsString('<fault>', $body);
		$this->assertStringNotContainsString('{', $body);
		$this->assertStringContainsString('<name>faultCode</name><value><int>429</int></value>', $body);
	}

	public function testRatePerIpZeroDisablesLimitingEntirely(): void
	{
		$cache      = $this->workingCache();
		$middleware = $this->middleware($cache, 0);
		$handler    = $this->countingHandler();

		// Far more calls than any real limit would tolerate; all must pass.
		for ($i = 0; $i < 50; $i++) {
			$response = $middleware->process($this->request(), $handler);
			$this->assertSame(200, $response->getStatusCode());
		}

		$this->assertSame(50, $handler->calls);
	}

	public function testFailsOpenWhenTheCacheBackendIsUnavailable(): void
	{
		$middleware = $this->middleware($this->brokenCache(), 1);
		$handler    = $this->countingHandler();

		// The limit is 1, which would reject every call past the first if the
		// counter worked — but with no cache backend able to persist a count,
		// every call must still be let through rather than blocked.
		for ($i = 0; $i < 5; $i++) {
			$response = $middleware->process($this->request(), $handler);
			$this->assertSame(200, $response->getStatusCode());
		}

		$this->assertSame(5, $handler->calls);
	}

	public function testTracksEachIpSeparately(): void
	{
		$cache      = $this->workingCache();
		$middleware = $this->middleware($cache, 1);
		$handler    = $this->countingHandler();

		$first  = $middleware->process($this->request('203.0.113.5'), $handler);
		$second = $middleware->process($this->request('203.0.113.9'), $handler);

		$this->assertSame(200, $first->getStatusCode());
		$this->assertSame(200, $second->getStatusCode());
		$this->assertSame(2, $handler->calls);
	}
}
