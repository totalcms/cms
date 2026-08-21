<?php

declare(strict_types=1);

use Mcp\Schema\JsonRpc\MessageInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\postJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Build a JSON-RPC 2.0 initialize payload matching the MCP 2025-06-18 spec.
 *
 * @return array<string,mixed>
 */
function mcpInitializePayload(): array
{
	return [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest', 'version' => '0.1'],
		],
	];
}

describe('McpEndpointAction', function (): void {
	it('route exists at POST /mcp', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		// Always-served route: 200 (active), 401 (publicAccess off, no key),
		// 403 (non-Pro), or 404 (mcp.enabled=false). Should never be 405.
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404]);
	});

	it('returns JSON content-type for all gated responses', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
	});

	it('returns structured error body on 401 / 403 / 404', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());
		$status   = $response->getStatusCode();

		if (in_array($status, [401, 403, 404], true)) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body)->toHaveKey('error');
			expect($body['error'])->toHaveKey('message');
			expect($body['error']['message'])->toBeString();
		}
	});

	it('non-Pro edition error body carries edition and required fields', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		if ($response->getStatusCode() === 403) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body['error'])->toHaveKeys(['message', 'edition', 'required']);
			expect($body['error']['required'])->toBe('pro');
		}
	});

	it('emits WWW-Authenticate header on 401 keyed by reason (login_required vs invalid_token)', function (): void {
		// Mandatory for Anthropic Directory: MCP hosts use WWW-Authenticate to
		// pick the right lazy-auth UX. login_required = "you need to log in";
		// invalid_token = "your credentials didn't work, try again." Without
		// the header, hosts can't distinguish.
		$response = postJson('/mcp', mcpInitializePayload());

		if ($response->getStatusCode() === 401) {
			$header = $response->getHeaderLine('WWW-Authenticate');
			expect($header)->toStartWith('Bearer realm="MCP"');
			expect($header)->toMatch('/error="(login_required|invalid_token)"/');
		}
	});

	it('rejects bogus API key with 401', function (): void {
		// Use the slim-test helper's underlying request — we need to pass an
		// X-API-Key header. Slim test helpers don't support headers directly,
		// so build via the app instance.
		$app     = $this->app;
		$request = (new Nyholm\Psr7\Factory\Psr17Factory())
			->createServerRequest('POST', '/mcp')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withHeader('X-API-Key', 'tcms_definitely-not-a-real-key');
		$request->getBody()->write((string)json_encode(mcpInitializePayload()));
		$request->getBody()->rewind();

		$response = $app->handle($request);

		// 401 (invalid key) or 403 (non-Pro env beats auth check). Never 200.
		expect($response->getStatusCode())->toBeIn([401, 403, 404]);
	});

	it('returns successful JSON-RPC initialize response when conditions allow', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		if ($response->getStatusCode() === 200) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body)->toHaveKeys(['jsonrpc', 'id'])
				->and($body['jsonrpc'])->toBe('2.0')
				->and($body['id'])->toBe(1);

			// Successful initialize carries result with protocolVersion + capabilities
			expect($body)->toHaveKey('result');
			expect($body['result'])->toHaveKeys(['protocolVersion', 'capabilities', 'serverInfo']);

			// The SDK's InitializeHandler does NOT echo the version the client
			// requested (mcpInitializePayload() above pins '2025-06-18' as a fixed
			// client stance) — it always answers with the version it itself
			// advertises (Mcp\Schema\JsonRpc\InitializeHandler returns
			// $configuration->protocolVersion, which we never set, so it falls
			// back to MessageInterface::PROTOCOL_VERSION). That is spec-legal: a
			// server may respond with a different protocol version than the one
			// requested if it doesn't support it, and it's the CLIENT's job to
			// decide whether to proceed or disconnect. So assert against the
			// SDK's own constant instead of a hardcoded literal, which is exactly
			// what drifted here (the SDK bumped its default from 2025-06-18 to
			// 2025-11-25) — pinning to the source of truth means this test can
			// never go stale on the next SDK bump.
			expect($body['result']['protocolVersion'])->toBe(MessageInterface::PROTOCOL_VERSION->value);
		}
	});

	it('GET /mcp without the SSE Accept header returns 405', function (): void {
		// SDK's StreamableHttpTransport dispatches on method+Accept; we route
		// both POST and GET to the same Action, so the route itself always
		// resolves — that's no longer what this test proves.
		//
		// Pre-948a24cb2, this asserted the status was NOT 405, on the theory
		// that 405 meant Slim couldn't find a GET handler for the route. That
		// commit deliberately introduced a real 405 case: a bare GET without
		// `Accept: text/event-stream` now falls through to the SDK's
		// StreamableHttpTransport, which only dispatches OPTIONS/POST/DELETE
		// and returns its spec-legal 405 for GET. The Accept-header gate
		// exists specifically so a plain GET from a browser, crawler, or
		// uptime monitor can't hold a PHP-FPM worker open on the SSE
		// listening-stream path (see listeningStreamResponse()). So 405 here
		// is intended behaviour, not a routing failure — assert that instead
		// of the inverse. The SSE-upgrade success path (GET with
		// `Accept: text/event-stream`) is already covered separately below,
		// in the "listening stream (GET)" describe block.
		$response = \TotalCMS\Slim\Pest\get('/mcp');

		// A blocked edition/enabled gate can still short-circuit before the
		// SDK is ever reached, matching the pattern used elsewhere in this file.
		expect($response->getStatusCode())->toBeIn([405, 403, 404]);
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Bounded SSE "listening stream" for GET /mcp (mcp.listeningStream)
//
// OpenAI's plugin submission scanner probes GET /mcp expecting an SSE upgrade
// and treats the SDK's spec-legal 405 (no server-initiated stream) as a
// failure. When mcp.listeningStream is on, T3 answers with a bounded
// keepalive-only SSE stream instead — but only for callers who'd already pass
// the POST auth gate (same McpAuth::resolvePersona() call). See
// McpEndpointAction::listeningStreamResponse().
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Trigger the CallbackStream body — same technique McpStreamingTest uses,
 * with one addition: the callback's `@ob_flush()` sends its content to
 * whatever output buffer is immediately enclosing it, not all the way out to
 * the terminal. A single ob_start() would see an empty buffer (content
 * already flushed past it) — nesting a second buffer gives ob_flush()
 * somewhere to land that we can still read.
 */
function triggerListeningStreamBody(TotalCMS\Slim\Test\TestResponse $response): string
{
	ob_start();
	ob_start();
	$response->getBody()->__toString();
	ob_end_clean();

	return (string)ob_get_clean();
}

describe('McpEndpointAction — listening stream (GET)', function (): void {
	it('GET with SSE Accept, listeningStream on, and public access on returns a 200 SSE stream', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'    => true,
			'listeningStream' => true,
			// Cap is exercised by its own tests below; disabled here so an
			// unrelated assertion can never fail on a shared counter.
			'listeningStreamMaxConcurrent' => 0,
		]);

		$response = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
		$status   = $response->getStatusCode();

		// Edition/enabled gates run before persona resolution and are outside
		// this feature's control — skip-safe pass if they block the endpoint,
		// matching the pattern the rest of this file uses.
		if ($status !== 200) {
			expect($status)->toBeIn([403, 404]);

			return;
		}

		expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');
		expect($response->getHeaderLine('Cache-Control'))->toContain('no-cache');
		expect($response->getHeaderLine('Cache-Control'))->toContain('no-transform');
		expect($response->getHeaderLine('Connection'))->toContain('keep-alive');
		expect($response->getHeaderLine('X-Accel-Buffering'))->toBe('no');
	});

	it('GET without an SSE Accept header falls through to the SDK 405, even with listeningStream on', function (): void {
		// The cheapest, largest reduction in accidental surface: browsers,
		// crawlers, uptime monitors, and link checkers all send a bare GET
		// with no `Accept: text/event-stream` — none of them should hang for
		// the listening-stream window.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'    => true,
			'listeningStream' => true,
			// Cap is exercised by its own tests below; disabled here so an
			// unrelated assertion can never fail on a shared counter.
			'listeningStreamMaxConcurrent' => 0,
		]);

		$response = \TotalCMS\Slim\Pest\get('/mcp');
		$status   = $response->getStatusCode();

		expect($status)->toBeIn([405, 403, 404]);
		expect($response->getHeaderLine('Content-Type'))->not()->toContain('text/event-stream');
	});

	it('GET with listeningStream off falls through to the SDK 405', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'    => true,
			'listeningStream' => false,
			// Cap is exercised by its own tests below; disabled here so an
			// unrelated assertion can never fail on a shared counter.
			'listeningStreamMaxConcurrent' => 0,
		]);

		$response = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
		$status   = $response->getStatusCode();

		// With the switch off, GET reaches the SDK transport, which dispatches
		// only OPTIONS/POST/DELETE and returns its spec-legal 405 for GET. A
		// blocked edition/enabled gate (403/404) can still short-circuit first.
		expect($status)->toBeIn([405, 403, 404]);
		expect($response->getHeaderLine('Content-Type'))->not()->toContain('text/event-stream');
	});

	it('GET with publicAccess off and no credentials is 401, never a 200 stream', function (): void {
		// This is the security-critical assertion: an anonymous caller who
		// would be rejected on POST must be rejected identically on GET,
		// BEFORE any SSE stream (and PHP-FPM worker) is opened. Default test
		// config already ships publicAccess=false; only listeningStream needs
		// pinning true so we're exercising the branch that would otherwise
		// open a stream. Sends the SSE Accept header so this is the realistic
		// "unauthenticated scanner" scenario, not just a bare GET.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'    => false,
			'listeningStream' => true,
			// Cap is exercised by its own tests below; disabled here so an
			// unrelated assertion can never fail on a shared counter.
			'listeningStreamMaxConcurrent' => 0,
		]);

		$response = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
		$status   = $response->getStatusCode();

		// Never a stream — that is the invariant this test exists to prove.
		expect($status)->not()->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->not()->toContain('text/event-stream');

		// When the endpoint itself is reachable (edition/enabled gates pass),
		// the specific outcome must be 401 with the same WWW-Authenticate
		// challenge McpAuth produces for POST.
		if ($status === 401) {
			$header = $response->getHeaderLine('WWW-Authenticate');
			expect($header)->toStartWith('Bearer realm="MCP"');
			expect($header)->toMatch('/error="login_required"/');
		} else {
			expect($status)->toBeIn([403, 404]);
		}
	});

	it('a 1-second window actually runs the keepalive loop before closing', function (): void {
		// This is the coverage gap a zero-second window can't close: with
		// seconds=0 the `while` body never executes, so nothing exercises the
		// usleep/chunking/connection_aborted() loop that is the code actually
		// holding the worker in production. seconds=1 forces one real pass:
		// the initial keepalive, one ~1s sleep, a second keepalive, then the
		// loop condition ends it — never lasting the full 5s config default.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'           => true,
			'listeningStream'        => true,
			'listeningStreamSeconds' => 1,
			// Cap is exercised by its own tests below; disabled here so an
			// unrelated assertion can never fail on a shared counter.
			'listeningStreamMaxConcurrent' => 0,
			// Pinned rather than left to the default so this assertion tests
			// that config reaches the wire, not that a particular default is
			// still in place.
			'listeningStreamRetryMs' => 4321,
		]);

		$response = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
		$status   = $response->getStatusCode();

		if ($status !== 200) {
			expect($status)->toBeIn([403, 404]);

			return;
		}

		$startedAt = microtime(true);
		$captured  = triggerListeningStreamBody($response);
		$elapsed   = microtime(true) - $startedAt;

		// At least two keepalive records: the initial one plus one loop pass.
		expect(substr_count($captured, ': keepalive'))->toBeGreaterThanOrEqual(2);
		expect($captured)->toContain('retry: 4321');

		// The loop actually held the worker for roughly the window (not an
		// instant return) but terminated close to it (not hung indefinitely).
		expect($elapsed)->toBeGreaterThanOrEqual(0.9);
		expect($elapsed)->toBeLessThan(3.0);
	});

	it('a 0-second window still answers the probe but releases the worker immediately', function (): void {
		// Zero is now a legal, supported setting rather than something the
		// clamp rounds away — and it is the whole point of the change: a probe
		// only checks that it got a 200 with `text/event-stream` carrying an
		// event, all of which is written before the wait loop. So a 0 window
		// satisfies every scanner while costing ~0 worker time, which is what
		// makes the feature safe to leave on under `pm.max_children` pressure.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'                 => true,
			'listeningStream'              => true,
			'listeningStreamSeconds'       => 0,
			'listeningStreamMaxConcurrent' => 0,
		]);

		$response = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
		$status   = $response->getStatusCode();

		if ($status !== 200) {
			expect($status)->toBeIn([403, 404]);

			return;
		}

		expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');

		$startedAt = microtime(true);
		$captured  = triggerListeningStreamBody($response);
		$elapsed   = microtime(true) - $startedAt;

		// The probe still gets a real event — this is what a scanner checks.
		expect($captured)->toContain(': keepalive');
		expect($captured)->toContain('retry: ');

		// And the worker came straight back. The old floor held it ~1s; the
		// assertion is deliberately loose (well under that) so it proves the
		// loop was skipped without being flaky on a slow CI box.
		expect($elapsed)->toBeLessThan(0.5);
	});

	it('falls through to the SDK 405 once the concurrent-stream cap is reached', function (): void {
		// The cap is the only bound that applies to OAuth-authenticated
		// callers (McpRateLimitMiddleware exempts anything sending
		// `Authorization: Bearer ...`) and the only one that bounds the
		// aggregate rather than a single IP. Over the cap the caller gets the
		// SDK's ordinary spec-legal 405 — identical to `listeningStream` being
		// off — so no client sees a novel failure mode.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'    => true,
			'listeningStream' => true,
			// Non-zero: at a zero window the cap deliberately no-ops (there is
			// no occupancy to bound), so a 0 here would exempt the very
			// behaviour this test exists to prove.
			'listeningStreamSeconds'       => 1,
			'listeningStreamMaxConcurrent' => 1,
		]);

		/** @var CacheManager $cache */
		$cache = $this->app->getContainer()->get(CacheManager::class);
		$cache->clearData('mcp_listening_stream_slots');

		$first  = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
		$status = $first->getStatusCode();

		if ($status !== 200) {
			expect($status)->toBeIn([403, 404]);

			return;
		}

		expect($first->getHeaderLine('Content-Type'))->toContain('text/event-stream');

		// Second open inside the same window exceeds a cap of 1.
		$second = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);

		expect($second->getStatusCode())->toBe(405);
		expect($second->getHeaderLine('Content-Type'))->not()->toContain('text/event-stream');
	});

	it('does not consume a cap slot when the window is 0', function (): void {
		// A zero window releases the worker before the next request arrives, so
		// there is no concurrency to bound and the cap must stand aside. If it
		// does not, the 1s TTL floor turns it into a global opens-per-second
		// limit — a second, coarser rate limiter pooling every caller into one
		// bucket, which refuses probes during ordinary traffic. Observed in
		// production as 16/20 probes admitted at a cap of 20 with no worker
		// anywhere near being held; a refused probe is precisely the 405 the
		// listening stream exists to avoid.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'           => true,
			'listeningStream'        => true,
			'listeningStreamSeconds' => 0,
			// Deliberately lower than the number of requests below: with the
			// bypass working, the cap is never consulted.
			'listeningStreamMaxConcurrent' => 1,
		]);

		/** @var CacheManager $cache */
		$cache = $this->app->getContainer()->get(CacheManager::class);
		$cache->clearData('mcp_listening_stream_slots');

		$first = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);

		if ($first->getStatusCode() !== 200) {
			expect($first->getStatusCode())->toBeIn([403, 404]);

			return;
		}

		// Every one of these would be a 405 if a zero window still consumed
		// slots against a cap of 1.
		foreach (range(1, 5) as $_) {
			$next = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
			expect($next->getStatusCode())->toBe(200);
			expect($next->getHeaderLine('Content-Type'))->toContain('text/event-stream');
		}
	});

	it('does not cap when listeningStreamMaxConcurrent is 0', function (): void {
		// `<= 0` disables the cap, matching McpRateLimitMiddleware's
		// convention for its own limit. Without this an operator setting 0
		// expecting "no limit" would instead get "no streams."
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'    => true,
			'listeningStream' => true,
			// Non-zero so this exercises the `$max <= 0` bypass specifically
			// and not the separate zero-window one.
			'listeningStreamSeconds'       => 1,
			'listeningStreamMaxConcurrent' => 0,
		]);

		/** @var CacheManager $cache */
		$cache = $this->app->getContainer()->get(CacheManager::class);
		$cache->clearData('mcp_listening_stream_slots');

		$first = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);

		if ($first->getStatusCode() !== 200) {
			expect($first->getStatusCode())->toBeIn([403, 404]);

			return;
		}

		foreach (range(1, 3) as $_) {
			$next = \TotalCMS\Slim\Pest\get('/mcp', ['Accept' => 'text/event-stream']);
			expect($next->getStatusCode())->toBe(200);
			expect($next->getHeaderLine('Content-Type'))->toContain('text/event-stream');
		}
	});
});
