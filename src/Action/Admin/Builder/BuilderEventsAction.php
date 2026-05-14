<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Builder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use TotalCMS\Domain\Builder\Service\BuilderReloadPulseService;
use TotalCMS\Domain\Cache\Service\DevModeManager;

/**
 * `GET /livereload/events` — Server-Sent Events stream powering the Builder's
 * live-reload feature.
 *
 * Connected pages hold this connection open and receive a `reload` event
 * whenever the pulse repository's timestamp advances (i.e. someone saves a
 * Builder template or page record). Clients call `location.reload()` on
 * receipt.
 *
 * Dev Mode is the sole gate. The route is registered as a public endpoint
 * because Dev Mode also bypasses Twig caching — anyone watching the site in
 * a dev context should get the reload, which is what makes the demo case
 * work (developer + client both watching, both reload on save). With Dev
 * Mode off, the action refuses the connection with 403; the injector also
 * stops emitting the script, so there's nothing trying to connect.
 *
 * Streaming details:
 *   - Connection lifetime is capped at ~30 seconds. The browser's `EventSource`
 *     auto-reconnects on close, so the cap recycles workers without affecting
 *     UX. Without it, a worker could be tied up indefinitely.
 *   - `session_write_close()` runs immediately so we don't hold the session
 *     lock while streaming. Other requests from the same browser would
 *     otherwise block until this connection closes.
 *   - `output_buffering` is forced off and `flush()` is called per heartbeat
 *     to defeat fastcgi/proxy buffering.
 *   - A heartbeat comment is sent every poll so reverse proxies don't drop
 *     the connection on idle timeouts.
 */
final readonly class BuilderEventsAction
{
	private const POLL_INTERVAL_MS = 500;
	private const MAX_LIFETIME_SEC = 30;

	public function __construct(
		private BuilderReloadPulseService $pulse,
		private DevModeManager $devModeManager,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		// Dev Mode is the sole gate. With it off, the injector also stops
		// emitting the script, so the only way to reach here is a stale page
		// that was loaded while Dev Mode was on. 403 lets the browser see a
		// permanent close rather than retrying indefinitely.
		if (!$this->devModeManager->isDevModeActive()) {
			return $response->withStatus(403);
		}

		// Release the session lock immediately. Sessions are per-file-locked by
		// PHP — without this, every other request from the same browser would
		// block waiting for this stream to finish. Use the bare PHP API rather
		// than the Odan wrapper; SessionInterface doesn't expose `save()` and
		// the underlying need is "release the lock now," which is exactly what
		// session_write_close() does.
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		$this->prepareForStreaming();

		// IMPORTANT: emit SSE headers via PHP's `header()` BEFORE the first
		// `echo`. By the time __invoke() returns, PHP has already flushed the
		// default `Content-Type: text/html` to the browser, and Slim's
		// emitter can't override headers that are already sent. Setting them
		// on the Response object alone is too late — the browser sees text/html
		// and aborts the EventSource connection.
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache, no-transform');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no');

		$lastSeenTs = $this->pulse->currentTimestamp();
		$startedAt  = time();

		// Initial comment + retry hint. The retry value tells the browser how
		// long to wait before reconnecting if we drop the connection.
		echo ": connected\n";
		echo "retry: 2000\n\n";
		$this->flush();

		while (time() - $startedAt < self::MAX_LIFETIME_SEC) {
			if (connection_aborted() === 1) {
				break;
			}

			$current = $this->pulse->current();
			if ($current !== null && $current['ts'] > $lastSeenTs) {
				$lastSeenTs = $current['ts'];
				echo "event: reload\n";
				echo 'data: ' . json_encode($current, JSON_UNESCAPED_SLASHES) . "\n\n";
				$this->flush();
			} else {
				// Heartbeat — keeps proxies from idling us out and gives the
				// connection_aborted() check above something to react to.
				echo ": heartbeat\n\n";
				$this->flush();
			}

			usleep(self::POLL_INTERVAL_MS * 1000);
		}

		// Empty body — we already wrote directly to the output buffer.
		return $response->withBody(new Stream(fopen('php://temp', 'r+b') ?: throw new \RuntimeException('temp stream')));
	}

	/**
	 * Disable PHP's output buffer so events flush instantly. Without this,
	 * fastcgi or PHP-level buffering would coalesce events into a single
	 * end-of-request flush — exactly the opposite of what SSE needs.
	 */
	private function prepareForStreaming(): void
	{
		while (ob_get_level() > 0) {
			ob_end_flush();
		}
		@ini_set('output_buffering', 'off');
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');
		ignore_user_abort(false);
	}

	private function flush(): void
	{
		if (ob_get_level() > 0) {
			ob_flush();
		}
		flush();
	}
}
