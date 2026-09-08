<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mcp;

use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Service\McpListeningStream;
use TotalCMS\Domain\Mcp\Service\McpRequestAuthorizer;
use TotalCMS\Domain\Mcp\Service\McpRequestBody;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Service\McpTransportSecurity;
use TotalCMS\Domain\Mcp\Service\ToolsOnlyClients;
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
 * McpListeningStream::response()) when mcp.listeningStream is on — auth-gated and
 * Origin-checked identically to POST. Any other GET (no SSE Accept header, the
 * config switch off, or a disallowed Origin in restricted mode) falls through
 * to the SDK, which returns its spec-legal 405 (no server-initiated stream) —
 * or, for the Origin case, we 403 directly since the SDK is never reached.
 */
readonly class McpEndpointAction
{
	public function __construct(
		private McpServerFactory $serverFactory,
		private McpRequestAuthorizer $authorizer,
		private McpListeningStream $listeningStream,
		private EditionFeatureService $editionFeatures,
		private JsonRenderer $renderer,
		private Config $config,
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

		$body    = McpRequestBody::from($request);
		$persona = $this->authorizer->authorize($request, $response, $body);
		if ($persona instanceof ResponseInterface) {
			return $persona;
		}

		// Read the client's self-reported identity from the initialize handshake.
		// It drives two things: the mcp-activity log line, and the tools-only
		// decision below. clientInfo is only present on `initialize`; subsequent
		// requests in the session leave $toolsOnly false, which is harmless —
		// capabilities are negotiated once at initialize, so a ChatGPT client that
		// was told "tools only" never asks for resources/prompts afterwards.
		$toolsOnly = false;
		if ($body->isInitialize()) {
			['name' => $name, 'version' => $version] = $body->clientInfo();
			$this->serverFactory->logClientInfo($name, $version);

			// ChatGPT/OpenAI clients are served a tools-only surface (no
			// resources/prompts) — their connector importer rejects servers that
			// advertise those. Every other client keeps the full surface.
			$toolsOnly = ToolsOnlyClients::matches($name);
		}

		// McpRequestBody::from() already rewound the stream; this call is kept
		// as a guard so a future branch above that reads the stream cannot
		// leave the SDK transport at EOF.
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
			if ($this->listeningStream->reserveSlot($this->listeningStream->seconds())) {
				return $this->listeningStream->response($response);
			}
		}

		// Admission control for modern-era (2026-07-28) `subscriptions/listen`.
		//
		// That method is a long-lived SSE stream: StatelessProtocol::listen()
		// holds one PHP-FPM worker for mcp.subscriptionStreamSeconds, polling the bus
		// every 250ms. It is the same worker-occupancy problem as the GET
		// keepalive stream below, and it is bounded the same way — by the one
		// counter that applies site-wide and does not exempt OAuth callers, as
		// the note in config/defaults.php explains at length.
		//
		// Two refusals, both answered as a JSON-RPC error rather than a
		// transport failure so a client can tell "not now" from "broken":
		//   1. subscriptions are switched off, in which case no bus is wired and
		//      the stream would hold a worker to deliver nothing at all;
		//   2. the site-wide concurrency cap is already spent.
		if ($body->isListen()) {
			$subscriptionsOn = ($this->config->mcp['subscriptionsEnabled'] ?? true) !== false;

			if (!$subscriptionsOn || !$this->listeningStream->reserveSlot($this->serverFactory->subscriptionStreamSeconds())) {
				return $this->renderer->json($response, [
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => [
						'code'    => -32000,
						'message' => $subscriptionsOn
							? 'Too many concurrent subscription streams; retry shortly.'
							: 'Resource subscriptions are disabled on this server.',
					],
				], $subscriptionsOn ? 503 : 501);
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
		//
		// ProtocolVersionMiddleware is deliberately NOT in this list. Custom
		// middleware runs at the edge, before InboundClassifier decides which
		// protocol era a request belongs to, and that middleware only recognises
		// handshake revisions — so listing it here rejects every modern
		// (2026-07-28) request before the stateless dispatcher ever sees it. The
		// transport applies it itself, to handshake traffic only, via
		// StreamableHttpTransport::handshakeMiddleware(). The SDK logs a warning
		// if it finds one in a custom list; putting it back reintroduces the bug
		// that warning describes.
		$middleware = [];
		if (!McpTransportSecurity::isOpen($allowedOrigins)) {
			$middleware[] = new DnsRebindingProtectionMiddleware(
				McpTransportSecurity::allowedHosts($allowedOrigins, $request->getUri()->getHost()),
			);
		}

		$transport = new StreamableHttpTransport($request, middleware: $middleware);

		return $server->run($transport);
	}
}
