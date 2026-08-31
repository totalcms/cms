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
use TotalCMS\Middleware\Security\RateLimitMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * `RateLimitMiddleware` is the only thing between `POST /api/action/mailer` and
 * an open mail relay. The endpoint behind it (`SendEmailAction`) is fully
 * covered; the guard in front of it had never executed in a test.
 *
 * Structured after XmlRpcRateLimitMiddlewareTest, the suite's other rate-limiter
 * test, since the doubles and the questions are the same.
 */
final class RateLimitMiddlewareTest extends TestCase
{
	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * `Config` has a huge constructor, so build one via reflection and set only
	 * the `mailer` array this middleware reads.
	 *
	 * @param array<string,mixed> $mailer
	 */
	private function config(array $mailer): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'mailer'))->setValue($config, $mailer);

		return $config;
	}

	/**
	 * An in-memory `CacheManager` double. `CacheManager` is neither `final` nor
	 * `readonly`, so a constructorless anonymous subclass is enough.
	 */
	private function workingCache(): CacheManager
	{
		return new class extends CacheManager {
			/** @var array<string,mixed> */
			public array $store = [];

			/** @var array<string,int> */
			public array $ttls = [];

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
				$this->ttls[$key]  = $ttl;

				return true;
			}
		};
	}

	/**
	 * Every cache backend unreachable: `getData()` returns null (CacheManager's
	 * real behaviour when all backends are down) and `storeData()` persists
	 * nothing, so the counter can never climb.
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

	/** @param array<string,mixed> $mailer */
	private function middleware(CacheManager $cache, array $mailer): RateLimitMiddleware
	{
		return new RateLimitMiddleware($cache, new JsonRenderer(), $this->config($mailer));
	}

	/**
	 * @param array<string,string> $headers
	 * @param array<string,mixed>  $body
	 */
	private function request(
		string $remoteAddr = '203.0.113.5',
		array $headers = [],
		array $body = [],
	): ServerRequestInterface {
		$request = (new Psr17Factory())
			->createServerRequest('POST', '/api/action/mailer', ['REMOTE_ADDR' => $remoteAddr]);

		foreach ($headers as $name => $value) {
			$request = $request->withHeader($name, $value);
		}

		return $body === [] ? $request : $request->withParsedBody($body);
	}

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

	/** @return array<string,mixed> */
	private function decode(ResponseInterface $response): array
	{
		$response->getBody()->rewind();

		return (array)json_decode((string)$response->getBody(), true);
	}

	// ── Passing traffic ──────────────────────────────────────────────────────

	public function testAllowsRequestsUnderTheLimit(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 3]);
		$handler    = $this->countingHandler();

		$response = $middleware->process($this->request(), $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(1, $handler->calls);
	}

	public function testAdvertisesTheRemainingAllowanceOnAPassingRequest(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 3, 'rateWindow' => 120]);

		$response = $middleware->process($this->request(), $this->countingHandler());

		// Remaining counts the request being served: 3 allowed, this is the
		// first, so 2 are left. A caller pacing itself off this header would
		// overshoot by one if it reported the pre-increment count.
		$this->assertSame('3', $response->getHeaderLine('X-RateLimit-IP-Limit'));
		$this->assertSame('2', $response->getHeaderLine('X-RateLimit-IP-Remaining'));
		$this->assertSame('120', $response->getHeaderLine('X-RateLimit-Window'));
	}

	public function testCountsDownAcrossSuccessiveRequestsAndThenBlocks(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 3]);
		$handler    = $this->countingHandler();
		$request    = $this->request();

		$remaining = [];
		for ($i = 0; $i < 3; $i++) {
			$remaining[] = $middleware->process($request, $handler)->getHeaderLine('X-RateLimit-IP-Remaining');
		}

		$this->assertSame(['2', '1', '0'], $remaining);
		$this->assertSame(3, $handler->calls);

		// The fourth is refused, and must not reach the mailer.
		$blocked = $middleware->process($request, $handler);
		$this->assertSame(429, $blocked->getStatusCode());
		$this->assertSame(3, $handler->calls);
	}

	// ── Blocking ─────────────────────────────────────────────────────────────

	public function testBlocksAtTheLimitNotOneRequestPast(): void
	{
		// The check is `>=`, so a limit of 1 permits exactly one request.
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1]);
		$handler    = $this->countingHandler();

		$this->assertSame(200, $middleware->process($this->request(), $handler)->getStatusCode());
		$this->assertSame(429, $middleware->process($this->request(), $handler)->getStatusCode());
		$this->assertSame(1, $handler->calls);
	}

	public function testTheBlockedResponseTellsTheCallerWhenToComeBack(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1, 'rateWindow' => 300]);
		$handler    = $this->countingHandler();

		$middleware->process($this->request(), $handler);
		$response = $middleware->process($this->request(), $handler);

		// Retry-After is the contract a well-behaved client backs off on;
		// without it the only option is to keep hammering.
		$this->assertSame('300', $response->getHeaderLine('Retry-After'));
		$this->assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
		$this->assertSame('300', $response->getHeaderLine('X-RateLimit-Window'));

		$payload = $this->decode($response);
		$this->assertFalse($payload['success']);
		$this->assertSame(300, $payload['retry_after']);
		$this->assertStringContainsString('IP', (string)$payload['error']);
	}

	public function testSeparateIpsGetSeparateAllowances(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1]);
		$handler    = $this->countingHandler();

		$middleware->process($this->request('203.0.113.5'), $handler);

		// One sender exhausting its allowance must not lock everyone else out.
		$this->assertSame(200, $middleware->process($this->request('198.51.100.9'), $handler)->getStatusCode());
		$this->assertSame(2, $handler->calls);
	}

	// ── Per-template cap ─────────────────────────────────────────────────────

	public function testBlocksOnTheTemplateCapEvenWhenTheIpIsFresh(): void
	{
		// The per-template cap is what stops a botnet — many IPs, one form —
		// from draining the mail quota, so it has to bite independently of IP.
		$cache      = $this->workingCache();
		$middleware = $this->middleware($cache, ['ratePerIp' => 100, 'ratePerTemplate' => 2]);
		$handler    = $this->countingHandler();

		$body = ['mailerId' => 'contact-form'];
		$middleware->process($this->request('203.0.113.1', [], $body), $handler);
		$middleware->process($this->request('203.0.113.2', [], $body), $handler);

		$response = $middleware->process($this->request('203.0.113.3', [], $body), $handler);

		$this->assertSame(429, $response->getStatusCode());
		$this->assertSame(2, $handler->calls);
		$this->assertStringContainsString('Template', (string)$this->decode($response)['error']);
	}

	public function testTemplateCountersAreKeptPerTemplate(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 100, 'ratePerTemplate' => 1]);
		$handler    = $this->countingHandler();

		$middleware->process($this->request('203.0.113.1', [], ['mailerId' => 'contact-form']), $handler);
		$response = $middleware->process($this->request('203.0.113.2', [], ['mailerId' => 'newsletter']), $handler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testARequestWithNoTemplateIsStillCountedAgainstTheIp(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1, 'ratePerTemplate' => 1]);
		$handler    = $this->countingHandler();

		$this->assertSame(200, $middleware->process($this->request(), $handler)->getStatusCode());
		$this->assertSame(429, $middleware->process($this->request(), $handler)->getStatusCode());
	}

	// ── Which address the counter is keyed on ────────────────────────────────

	public function testPrefersTheCloudflareHeaderOverTheSocketAddress(): void
	{
		// Behind Cloudflare every request arrives from a CF edge address, so
		// keying on REMOTE_ADDR would lump all visitors into one bucket.
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1]);
		$handler    = $this->countingHandler();

		$edge = ['CF-Connecting-IP' => '203.0.113.7'];
		$middleware->process($this->request('172.16.0.1', $edge), $handler);

		$sameSocketDifferentVisitor = ['CF-Connecting-IP' => '198.51.100.4'];
		$response                   = $middleware->process($this->request('172.16.0.1', $sameSocketDifferentVisitor), $handler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testTakesTheOriginalClientFromAForwardedForChain(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1]);
		$handler    = $this->countingHandler();

		// The client is the first entry; the rest are proxies it passed through.
		$middleware->process($this->request('172.16.0.1', ['X-Forwarded-For' => ' 203.0.113.9 , 70.41.3.18 ']), $handler);
		$response = $middleware->process($this->request('172.16.0.1', ['X-Forwarded-For' => '203.0.113.9, 10.0.0.1']), $handler);

		// Same client, different proxy chain: still the same bucket, and the
		// surrounding whitespace must not make it a different key.
		$this->assertSame(429, $response->getStatusCode());
	}

	public function testCloudflareHeaderWinsOverForwardedFor(): void
	{
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => 1]);
		$handler    = $this->countingHandler();

		$headers = ['CF-Connecting-IP' => '203.0.113.7', 'X-Forwarded-For' => '198.51.100.4'];
		$middleware->process($this->request('172.16.0.1', $headers), $handler);
		$response = $middleware->process($this->request('172.16.0.1', ['CF-Connecting-IP' => '203.0.113.7']), $handler);

		$this->assertSame(429, $response->getStatusCode());
	}

	// ── Configuration and failure posture ────────────────────────────────────

	public function testFallsBackToBuiltInLimitsWhenNothingIsConfigured(): void
	{
		// An install that never touched mailer settings still gets a cap.
		$middleware = $this->middleware($this->workingCache(), []);

		$response = $middleware->process($this->request(), $this->countingHandler());

		$this->assertSame('10', $response->getHeaderLine('X-RateLimit-IP-Limit'));
		$this->assertSame('300', $response->getHeaderLine('X-RateLimit-Window'));
	}

	public function testAcceptsNumericStringsFromSavedSettings(): void
	{
		// Settings round-trip through JSON, so these arrive as strings.
		$middleware = $this->middleware($this->workingCache(), ['ratePerIp' => '2', 'rateWindow' => '60']);
		$handler    = $this->countingHandler();

		$this->assertSame('2', $middleware->process($this->request(), $handler)->getHeaderLine('X-RateLimit-IP-Limit'));
		$middleware->process($this->request(), $handler);

		$this->assertSame(429, $middleware->process($this->request(), $handler)->getStatusCode());
	}

	public function testCountersExpireWithTheConfiguredWindow(): void
	{
		// The TTL is what makes the limit a rate rather than a lifetime quota.
		$cache      = $this->workingCache();
		$middleware = $this->middleware($cache, ['ratePerIp' => 5, 'rateWindow' => 90]);

		$middleware->process($this->request(), $this->countingHandler());

		$this->assertNotEmpty($cache->ttls);
		foreach ($cache->ttls as $ttl) {
			$this->assertSame(90, $ttl);
		}
	}

	public function testFailsOpenWhenTheCacheIsUnreachable(): void
	{
		// Documented posture: a broken cache must not lock out legitimate
		// senders. The counter reads zero forever, so traffic passes.
		$middleware = $this->middleware($this->brokenCache(), ['ratePerIp' => 1]);
		$handler    = $this->countingHandler();

		for ($i = 0; $i < 5; $i++) {
			$this->assertSame(200, $middleware->process($this->request(), $handler)->getStatusCode());
		}

		$this->assertSame(5, $handler->calls);
	}
}
