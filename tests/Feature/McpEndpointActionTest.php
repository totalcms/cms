<?php

declare(strict_types=1);

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
			expect($body['result']['protocolVersion'])->toBe('2025-06-18');
		}
	});

	it('GET /mcp resolves the same action', function (): void {
		// SDK's StreamableHttpTransport dispatches on method+Accept; we route
		// both POST and GET to the same Action. Verify the route resolves.
		$response = \TotalCMS\Slim\Pest\get('/mcp');

		// GET without SSE Accept usually yields a non-200 from the SDK protocol
		// layer, but the route must resolve (not 405 Method Not Allowed).
		expect($response->getStatusCode())->not()->toBe(405);
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
		expect($captured)->toContain('retry: 2000');

		// The loop actually held the worker for roughly the window (not an
		// instant return) but terminated close to it (not hung indefinitely).
		expect($elapsed)->toBeGreaterThanOrEqual(0.9);
		expect($elapsed)->toBeLessThan(3.0);
	});

	it('clamps a configured 0-second window up to the 1-second floor', function (): void {
		// Defends I2's clamp: an operator (or a bug) setting 0 — or anything
		// below the floor — must not turn the loop into a no-op that skips
		// straight to "closed," because that skips the one real keepalive
		// pass entirely.
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'publicAccess'           => true,
			'listeningStream'        => true,
			'listeningStreamSeconds' => 0,
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

		expect($captured)->toContain(': keepalive');
		// Clamped to the 1s floor, not 0 — proves the clamp is live, not just
		// documented.
		expect($elapsed)->toBeGreaterThanOrEqual(0.9);
	});
});
