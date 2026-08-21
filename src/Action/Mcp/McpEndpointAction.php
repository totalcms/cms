<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mcp;

use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Service\McpTransportSecurity;
use TotalCMS\Domain\Mcp\Service\McpUrlBuilder;
use TotalCMS\Domain\Mcp\Service\ToolsOnlyClients;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Main MCP endpoint at /mcp.
 *
 * Handles both POST (JSON-RPC requests) and GET (SSE upgrade for streaming);
 * the SDK's StreamableHttpTransport detects the method and Accept header to
 * route appropriately. We do not split into separate actions.
 *
 * Three early returns guard the endpoint before the SDK runs:
 *   - mcp.enabled false   → 404 (the endpoint should appear not to exist)
 *   - edition gate failed → 403 with a structured error body
 *   - invalid API key     → 401
 *
 * A fourth branch, after persona resolution but before the SDK transport is
 * built, answers a GET that asks for an SSE upgrade (Accept: text/event-stream)
 * with a bounded SSE "listening stream" of keepalive comments (see
 * listeningStreamResponse()) when mcp.listeningStream is on — auth-gated and
 * Origin-checked identically to POST. Any other GET (no SSE Accept header, the
 * config switch off, or a disallowed Origin in restricted mode) falls through
 * to the SDK, which returns its spec-legal 405 (no server-initiated stream) —
 * or, for the Origin case, we 403 directly since the SDK is never reached.
 */
readonly class McpEndpointAction
{
	/**
	 * Cache key for the global listening-stream admission counter. Shared by
	 * every request on the site, so CacheManager's domain prefixing keeps
	 * multi-site installs from sharing one budget.
	 */
	private const LISTENING_STREAM_SLOTS_KEY = 'mcp_listening_stream_slots';

	public function __construct(
		private McpServerFactory $serverFactory,
		private McpAuth $mcpAuth,
		private PersonaContext $personaContext,
		private EditionFeatureService $editionFeatures,
		private JsonRenderer $renderer,
		private Config $config,
		private OAuthScopeEvaluator $scopeEvaluator,
		private OAuthActivityLogger $activityLogger,
		private McpUrlBuilder $urlBuilder,
		private AccessControlService $accessControl,
		private CacheManager $cache,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if (!($this->config->mcp['enabled'] ?? true)) {
			return $this->renderer->json($response, [
				'error' => ['message' => 'MCP server is disabled on this site.'],
			], 404);
		}

		if (!$this->editionFeatures->can(EditionFeature::MCP_SERVER)) {
			return $this->renderer->json($response, [
				'error' => [
					'message'  => 'MCP is only available on Pro and higher editions.',
					'edition'  => $this->editionFeatures->getEdition()->value,
					'required' => 'pro',
				],
			], 403);
		}

		try {
			$persona = $this->mcpAuth->resolvePersona($request);
		} catch (McpAuthException $e) {
			// WWW-Authenticate triggers lazy-auth UX in MCP clients — the host
			// knows whether to prompt for credentials (login_required) vs surface
			// a "your token didn't work" message (invalid_token). Required for
			// Anthropic Directory submission.
			$response = $this->renderer->json($response, [
				'error' => ['message' => $e->getMessage()],
			], 401);

			return $response->withHeader(
				'WWW-Authenticate',
				sprintf(
					'Bearer realm="MCP", error="%s", resource_metadata="%s"',
					$e->reason,
					$this->urlBuilder->protectedResourceMetadataUrl($request),
				),
			);
		}

		// Stash the persona so individual tool handlers can read it during
		// dispatch. Must happen before build() since the SDK invokes handlers
		// synchronously from inside the server->run() call below.
		$this->personaContext->set($persona);

		// For Bearer / OAuth requests capture the validated scopes into
		// PersonaContext so OAuthScopeEvaluator can read them during tool
		// dispatch without needing direct access to the PSR-7 request.
		// Non-Bearer paths leave oauth_scopes null; the context keeps its
		// default empty array.
		$oauthScopes = $request->getAttribute('oauth_scopes');
		if (is_array($oauthScopes)) {
			/** @var list<string> $scopes */
			$scopes = array_values(array_map(
				static fn (mixed $s): string => is_object($s) && method_exists($s, 'getIdentifier')
					? (string)$s->getIdentifier()
					: (string)$s,
				$oauthScopes,
			));
			$this->personaContext->setScopes($scopes);

			// Client id, for oauth-activity log attribution when Task 7's
			// call-time guard denies a tools/call — same request attribute
			// BaseAccessMiddleware reads for the REST equivalent.
			$this->personaContext->setClientId((string)$request->getAttribute('oauth_client_id', ''));

			// Resolve the caller's UserAuthority for this Bearer request so
			// ToolRegistry::forPersona() (via McpServerFactory) can show
			// requirement-gated tools (McpToolDefinition::$requires,
			// introduced Phase 4 Task 6) to callers whose access-group grants
			// satisfy them, and so Task 7's call-time guard can re-check
			// per invocation. Session-free — mirrors BaseAccessMiddleware's
			// OAuth Bearer branch. Non-Bearer paths (API key / anonymous)
			// leave PersonaContext's authority at its default null.
			$userId = $request->getAttribute('oauth_user_id');
			if (is_string($userId) && $userId !== '') {
				$this->personaContext->setUserId($userId);
				$ref = OAuthUserRef::parse($userId, (string)$this->config->auth['collection']);
				$this->personaContext->setAuthority($this->accessControl->authorityFor($ref));
			}
		}

		// Scope-based gate for AUTHENTICATED persona. The persona filter has
		// already trimmed the tool/resource surface to what's visible at the
		// "authenticated" access level; this check enforces that the token's
		// scopes actually grant access to the specific JSON-RPC method being
		// invoked. ADMIN and PUBLIC_ personas skip this gate — ADMIN has
		// authority via API key; PUBLIC_ is gated by the visibility filter
		// alone (no scope concept).
		if ($persona === McpPersona::AUTHENTICATED) {
			// Slim's BodyParsingMiddleware may have already consumed the body stream.
			// Try getParsedBody() first (populated when Content-Type: application/json),
			// then fall back to reading the raw stream (rewind first in case it's at 0).
			$parsed = $request->getParsedBody();
			if (is_array($parsed)) {
				$rpc = $parsed;
			} else {
				$request->getBody()->rewind();
				$bodyText = $request->getBody()->getContents();
				$rpc      = json_decode($bodyText, true);
			}
			// Always rewind so the SDK's StreamableHttpTransport can call getContents()
			// from position 0.
			$request->getBody()->rewind();

			$method = is_array($rpc) && isset($rpc['method']) && is_string($rpc['method'])
				? $rpc['method']
				: '';

			// For tools/call, append the tool name so per-tool scopes can gate
			// individual invocations (foundation for future fine-grained scopes;
			// in v1 the mcp:tools scope covers all tool invocations via prefix
			// match on "tools/call").
			if ($method === 'tools/call' && isset($rpc['params']['name']) && is_string($rpc['params']['name'])) {
				$operation = 'tools/call:' . $rpc['params']['name'];
			} else {
				$operation = $method;
			}

			// Protocol lifecycle messages are exempt: ping keep-alives and the
			// notifications the spec obliges clients to send (initialized,
			// cancelled, progress) are plumbing, not capability access — no
			// scope lists them, so gating them 403s every OAuth client at the
			// handshake no matter what the token was granted.
			$isLifecycle = $method === 'ping' || str_starts_with($method, 'notifications/');

			if ($method !== '' && !$isLifecycle && !$this->scopeEvaluator->isAllowed($this->personaContext->getScopes(), $operation)) {
				$clientId = (string)$request->getAttribute('oauth_client_id', '');
				$this->activityLogger->scopeRejected($clientId, $operation, $this->personaContext->getScopes());

				$response = $this->renderer->json($response, [
					'error' => [
						'message'   => 'OAuth token scopes do not permit this MCP operation.',
						'operation' => $method,
					],
				], 403);

				return $response->withHeader(
					'WWW-Authenticate',
					sprintf(
						'Bearer realm="MCP", error="insufficient_scope", resource_metadata="%s"',
						$this->urlBuilder->protectedResourceMetadataUrl($request),
					),
				);
			}
		}

		// Read the client's self-reported identity from the initialize handshake.
		// It drives two things: the mcp-activity log line, and the tools-only
		// decision below. clientInfo is only present on `initialize`; subsequent
		// requests in the session leave $toolsOnly false, which is harmless —
		// capabilities are negotiated once at initialize, so a ChatGPT client that
		// was told "tools only" never asks for resources/prompts afterwards.
		$toolsOnly = false;
		$initBody  = $request->getParsedBody();
		if (!is_array($initBody)) {
			$request->getBody()->rewind();
			$decoded  = json_decode($request->getBody()->getContents(), true);
			$initBody = is_array($decoded) ? $decoded : [];
		}
		if (($initBody['method'] ?? null) === 'initialize') {
			$params  = is_array($initBody['params'] ?? null) ? $initBody['params'] : [];
			$client  = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
			$name    = is_string($client['name'] ?? null) ? $client['name'] : '';
			$version = is_string($client['version'] ?? null) ? $client['version'] : '';
			$this->serverFactory->logClientInfo($name, $version);

			// ChatGPT/OpenAI clients are served a tools-only surface (no
			// resources/prompts) — their connector importer rejects servers that
			// advertise those. Every other client keeps the full surface.
			$toolsOnly = ToolsOnlyClients::matches($name);
		}

		// Slim's BodyParsingMiddleware reads and consumes the body stream.
		// Rewind so the SDK's StreamableHttpTransport can call getContents()
		// from the beginning of the stream.
		$request->getBody()->rewind();

		// Computed early (before build()) because both the listening-stream
		// Origin check below and the transport's DnsRebindingProtectionMiddleware
		// further down need it.
		$allowedOrigins = (array)($this->config->mcp['allowedOrigins'] ?? []);

		// Bounded SSE "listening stream": some strict MCP clients (OpenAI's
		// plugin submission scanner among them) probe a bare GET with
		// `Accept: text/event-stream` and treat the SDK's spec-legal 405 (no
		// server-initiated stream) as a failure. Auth has already run above —
		// same persona resolution as POST, same 401/403 outcomes — so we only
		// reach here for a caller who was already allowed to talk to the
		// server. That's what keeps this from being an anonymous
		// worker-exhaustion vector. Placed above build() (which does a full
		// collection-meta disk scan the stream never needs) but still below
		// every auth/persona check above, and gated three ways:
		//   - the config switch (default on; off = let the SDK 405 as normal)
		//   - the client actually asking for an SSE upgrade (Accept header) —
		//     without this, any plain GET (browser, crawler, uptime monitor)
		//     would hang for the full window for nothing
		//   - Origin/Host validation in restricted-origin mode, since this
		//     branch returns before StreamableHttpTransport (and its
		//     DnsRebindingProtectionMiddleware, built below) is ever reached
		if (
			$request->getMethod() === 'GET'
			&& str_contains($request->getHeaderLine('Accept'), 'text/event-stream')
			&& (bool)($this->config->mcp['listeningStream'] ?? true)
		) {
			if (!McpTransportSecurity::originAllowed($request, $allowedOrigins)) {
				$message = $request->getHeaderLine('Origin') !== ''
					? 'Forbidden: Invalid Origin header.'
					: 'Forbidden: Invalid Host header.';
				$response->getBody()->write($message);

				return $response
					->withStatus(403)
					->withHeader('Content-Type', 'text/plain');
			}

			// Global admission control. The per-IP rate limiter
			// (McpRateLimitMiddleware) does not bound this: it exempts every
			// caller presenting `Authorization: Bearer ...`, which is every
			// OAuth-authenticated MCP client, and even for anonymous callers
			// it bounds one IP rather than the aggregate. N clients at the
			// per-IP limit can therefore hold N x window workers between them.
			// Over the cap we fall through to the SDK's normal 405 — the same
			// spec-legal answer the endpoint gave before this feature existed,
			// so a client that treats 405 as "no server-initiated stream"
			// degrades exactly as it would with `listeningStream` off.
			if ($this->reserveListeningStreamSlot()) {
				return $this->listeningStreamResponse($response);
			}
		}

		$server = $this->serverFactory->build($persona, $toolsOnly);

		// Compose the transport's HTTP middleware ourselves. The SDK's default
		// stack installs DnsRebindingProtectionMiddleware with a localhost-only
		// allowlist, which would 403 every production request (Host = the site's
		// domain). We apply DNS-rebinding / Origin enforcement only in restricted
		// mode (an explicit mcp.allowedOrigins list), scoped to the server's own
		// host plus the configured origins — satisfying the spec's
		// 403-on-invalid-Origin without breaking the open-by-default policy.
		// ProtocolVersionMiddleware is always on (validates the MCP-Protocol-Version
		// header; tolerant of its absence during initialize and for legacy clients).
		$middleware = [];
		if (!McpTransportSecurity::isOpen($allowedOrigins)) {
			$middleware[] = new DnsRebindingProtectionMiddleware(
				McpTransportSecurity::allowedHosts($allowedOrigins, $request->getUri()->getHost()),
			);
		}
		$middleware[] = new ProtocolVersionMiddleware();

		$transport = new StreamableHttpTransport($request, middleware: $middleware);

		return $server->run($transport);
	}

	/**
	 * Resolved, clamped duration of one listening stream, in seconds. Shared by
	 * the stream itself and by the admission counter's TTL so the two can never
	 * disagree about how long a stream lives.
	 */
	private function listeningStreamSeconds(): float
	{
		// Clamp regardless of what's configured — a fat-fingered (or
		// maliciously large) `mcp.listeningStreamSeconds` in tcms.php must not
		// be able to pin a worker for minutes/hours. Zero is legal and is the
		// cheapest setting that still satisfies a probe: the opening keepalive
		// and `retry:` are written before the loop, so the client still sees a
		// real 200 `text/event-stream` response with an event in it, and the
		// worker is released in milliseconds instead of held for the window.
		$configured = (float)($this->config->mcp['listeningStreamSeconds'] ?? 1);

		return max(0.0, min(30.0, $configured));
	}

	/**
	 * Reserve one of the globally-capped listening-stream slots.
	 *
	 * No-ops when the configured window is zero — see the guard below; there
	 * is no occupancy to bound and counting would degrade into a coarse global
	 * rate limit.
	 *
	 * Otherwise counts stream *opens* within a rolling window equal to the
	 * stream duration rather than tracking open/close pairs. Because every stream
	 * lives for exactly that window, opens-in-the-last-window is the
	 * concurrency — and an increment-only counter cannot drift. A
	 * decrement-on-close counter can: a worker killed mid-stream (FPM
	 * `request_terminate_timeout`, OOM, pool reload) never runs its decrement,
	 * the count ratchets upward, and under continuous traffic each increment
	 * refreshes the TTL so the leak never expires. The trade is fixed-window
	 * rather than sliding: a burst straddling a boundary can admit up to 2x
	 * the cap briefly, which is a far better failure mode than a gate that
	 * silently welds itself shut.
	 *
	 * Storage goes through CacheManager (Redis in production, graceful
	 * fallback to APCu/Memcached/filesystem). On APCu the counter is
	 * per-worker, so the effective cap is `listeningStreamMaxConcurrent x
	 * worker_count` — same caveat McpRateLimitMiddleware documents. Fails
	 * open: an unreachable cache reads zero and the stream is allowed, so a
	 * broken cache degrades to the pre-cap behaviour rather than disabling
	 * the feature.
	 */
	private function reserveListeningStreamSlot(): bool
	{
		$max = (int)($this->config->mcp['listeningStreamMaxConcurrent'] ?? 2);
		if ($max <= 0) {
			// Cap disabled, matching McpRateLimitMiddleware's convention for
			// its own `<= 0` limit.
			return true;
		}

		$seconds = $this->listeningStreamSeconds();
		if ($seconds <= 0.0) {
			// Nothing to bound. A zero-length window writes its keepalive and
			// returns, so the worker is free again before the next request
			// arrives and concurrency is ~0 however fast opens come in.
			//
			// Consuming a slot here would be actively harmful: the TTL below
			// floors at 1s, so at a 0 window the counter stops measuring
			// concurrent streams and starts measuring opens per second —
			// a global request-rate limit, which is McpRateLimitMiddleware's
			// job and which it does per-IP rather than pooling every caller
			// into one bucket. The observable effect was probes being refused
			// during ordinary traffic: 16/20 admitted at a cap of 20 with no
			// worker anywhere near being held. A refused probe is exactly the
			// 405 this whole feature exists to prevent.
			return true;
		}

		// TTL floor of 1s: sub-second TTLs are not portable across cache
		// backends. Only reached with a non-zero window, where the counter is
		// measuring real concurrency.
		$window = max(1, (int)ceil($seconds));
		$key    = self::LISTENING_STREAM_SLOTS_KEY;

		$count = $this->cache->getData($key);
		$count = is_int($count) ? $count : 0;

		if ($count >= $max) {
			return false;
		}

		$this->cache->storeData($key, $count + 1, $window);

		return true;
	}

	/**
	 * Emits a bounded SSE stream of keepalive comments and nothing else.
	 *
	 * T3 never has a server-initiated JSON-RPC message to push on this path
	 * (all real MCP traffic is POST request/response or POST-triggered SSE
	 * handled by the SDK transport above), so a keepalive-only stream is the
	 * whole contract here — it exists to give strict clients 200 + bytes
	 * instead of a 405.
	 *
	 * Streaming mechanism: `Mcp\Server\Transport\CallbackStream` is the same
	 * echo/flush-on-read PSR-7 stream the SDK itself uses for its
	 * progress-notification SSE (StreamableHttpTransport::flushOutgoingMessages()) —
	 * already a dependency, already the established pattern in this codebase.
	 * Slim's ResponseEmitter (driven by `$app->run()`) reads the body via
	 * `StreamInterface::read()`, which invokes our callback once; the callback
	 * does its own echo + @ob_flush() + flush() calls exactly like the SDK
	 * does, so bytes leave the process incrementally rather than being
	 * buffered until the callback returns.
	 *
	 * IMPORTANT (production, behind Cloudflare/nginx): a buffering reverse
	 * proxy holds the origin connection independently of the real client, so
	 * `connection_aborted()` never fires there even after the real client is
	 * long gone — the checks below only help on a direct, non-proxied
	 * connection (e.g. PHP's built-in dev server). Assume every stream holds
	 * its worker for the FULL configured window.
	 *
	 * Two things bound the cost in production, and both are needed: the window
	 * length (listeningStreamSeconds(), clamped to 0-30) caps how long any one
	 * stream holds its worker, and reserveListeningStreamSlot() caps how many
	 * may be open at once across all callers. The window alone is not enough —
	 * it bounds one stream, not the fleet.
	 */
	private function listeningStreamResponse(ResponseInterface $response): ResponseInterface
	{
		$seconds = $this->listeningStreamSeconds();
		$retryMs = max(0, (int)($this->config->mcp['listeningStreamRetryMs'] ?? 15000));

		$stream = new CallbackStream(static function () use ($seconds, $retryMs): void {
			// Defeat zlib output buffering — common on shared hosting, T3's
			// core audience. Without this, `flush()` below silently no-ops:
			// PHP buffers everything for compression and the client sees
			// nothing until the whole window elapses, at which point the
			// feature has failed at its one job while still paying the full
			// worker cost. Same guard this codebase's other production SSE
			// action already applies — see
			// BuilderEventsAction::prepareForStreaming().
			@ini_set('zlib.output_compression', '0');
			@ini_set('implicit_flush', '1');
			ignore_user_abort(false);

			// PHP 8.1+ built with zend-max-execution-timers (the default in
			// the official PHP Docker images) counts usleep() against
			// max_execution_time. Without this, a host with a short
			// max_execution_time (15/20/30s) can fatal mid-stream, and
			// because output has already started, the fatal's error text
			// lands inside the SSE body instead of a clean close. We restore
			// the original limit in `finally` below: under PHP-FPM/mod_php
			// each request gets a fresh process/timer anyway, but a
			// persistent CLI worker (this codebase's own test suite runs many
			// requests in one process) would otherwise carry our bumped timer
			// into whatever runs next and arm an unrelated fatal later.
			$originalTimeLimit = (int)ini_get('max_execution_time');
			@set_time_limit((int)ceil($seconds) + 5);

			try {
				if (connection_aborted() !== 0) {
					return;
				}

				// `retry:` tells the client (EventSource's auto-reconnect) how
				// long to wait before reconnecting once this window closes.
				// Without it a browser tab reconnects immediately in a tight
				// loop — effectively a permanently held worker. Sent once, on
				// the first record; the SSE spec updates the client's
				// reconnection timer whenever it sees the field, so one is enough.
				//
				// This is the single biggest lever on steady-state cost for a
				// well-behaved client: a client cycles `seconds` occupied then
				// `retry` idle, so it holds `seconds / (seconds + retry)` of a
				// worker continuously. At the old 2s value with a 5s window
				// that was 71% of a worker *per connected client*, forever.
				// Clients that ignore `retry:` are bounded by
				// reserveListeningStreamSlot() instead.
				echo ": keepalive\n";
				echo "retry: {$retryMs}\n\n";
				@ob_flush();
				flush();

				$start = microtime(true);
				while ((microtime(true) - $start) < $seconds) {
					$remaining = $seconds - (microtime(true) - $start);
					$sleep     = min(5.0, max(0.0, $remaining));
					if ($sleep <= 0.0) {
						break;
					}

					usleep((int)round($sleep * 1_000_000));

					if (connection_aborted() !== 0) {
						return;
					}

					echo ": keepalive\n\n";
					@ob_flush();
					flush();
				}
			} finally {
				@set_time_limit($originalTimeLimit);
			}
		});

		return $response
			->withStatus(200)
			->withHeader('Content-Type', 'text/event-stream')
			// no-transform is the portable anti-buffering signal — unlike
			// X-Accel-Buffering (nginx-only), Cloudflare and other
			// intermediaries respect it.
			->withHeader('Cache-Control', 'no-cache, no-transform')
			->withHeader('X-Accel-Buffering', 'no')
			->withHeader('Connection', 'keep-alive')
			->withBody($stream);
	}
}
