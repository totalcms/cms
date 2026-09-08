<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Mcp\Server\Transport\CallbackStream;
use Psr\Http\Message\ResponseInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Support\Config;

/**
 * The bounded SSE "listening stream" /mcp answers to a bare GET with
 * `Accept: text/event-stream`, and the site-wide admission counter it shares
 * with modern-era `subscriptions/listen`. Moved verbatim from
 * McpEndpointAction; the method docblocks below are the operator-facing
 * record of the admission model and are kept intact.
 */
final readonly class McpListeningStream
{
	/**
	 * Cache key for the global listening-stream admission counter. Shared by
	 * every request on the site, so CacheManager's domain prefixing keeps
	 * multi-site installs from sharing one budget.
	 */
	private const SLOTS_KEY = 'mcp_listening_stream_slots';

	public function __construct(
		private CacheManager $cache,
		private Config $config,
	) {
	}

	/**
	 * Resolved, clamped duration of one listening stream, in seconds. Shared by
	 * the stream itself and by the admission counter's TTL so the two can never
	 * disagree about how long a stream lives.
	 */
	public function seconds(): float
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
	 * Reserve one of the globally-capped stream slots.
	 *
	 * $seconds is how long the stream being admitted will actually live, and
	 * MUST be that stream's own window. The counter's correctness rests on
	 * "every stream lives for exactly this window, so opens-in-the-last-window
	 * IS the concurrency" — pass a different number and the count stops meaning
	 * anything. The two stream kinds have different durations
	 * (`listeningStreamSeconds` for the GET keepalive stream,
	 * `subscriptionStreamSeconds` for modern-era `subscriptions/listen`), which is why
	 * this is an argument rather than a second read of one setting.
	 *
	 * The budget itself is deliberately SHARED between them: both hold a worker
	 * from the same pool, so what matters to `pm.max_children` is the total, not
	 * how it splits. Two independent caps would let the pair reach 2x.
	 *
	 * No-ops when the passed window is zero — see the guard below; there
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
	public function reserveSlot(float $seconds): bool
	{
		$max = (int)($this->config->mcp['listeningStreamMaxConcurrent'] ?? 2);
		if ($max <= 0) {
			// Cap disabled, matching McpRateLimitMiddleware's convention for
			// its own `<= 0` limit.
			return true;
		}

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
		$key    = self::SLOTS_KEY;

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
	 * length (seconds(), clamped to 0-30) caps how long any one
	 * stream holds its worker, and reserveSlot() caps how many
	 * may be open at once across all callers. The window alone is not enough —
	 * it bounds one stream, not the fleet.
	 */
	public function response(ResponseInterface $response): ResponseInterface
	{
		$seconds = $this->seconds();
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
				// reserveSlot() instead.
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
