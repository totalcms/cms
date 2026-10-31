<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Odan\Session\Exception\SessionException;
use Odan\Session\SessionManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Support\BasePath;

/**
 * Drop-in replacement for Odan\Session\Middleware\SessionStartMiddleware that
 * skips starting the PHP session on the image-serving hot path.
 *
 * PHP's default (files) session handler holds an exclusive flock on the session
 * file for the ENTIRE request. With the session started on every request, a
 * page that loads N images through `/imageworks/...` (each a full Slim request)
 * serializes those requests on that lock instead of serving them in parallel —
 * a 20-image gallery pays a lock round-trip per image. ImageWorks responses
 * never read or write session state, so we don't start a session for them.
 *
 * Everything else behaves exactly like the upstream middleware: start the
 * session before the handler, save() it after. We deliberately do NOT
 * lazy-start by cookie presence for general requests — first-time visitors must
 * still get a session so CSRF tokens can be issued on form pages (see
 * CSRFTokenManager, which no-ops when the session isn't started).
 */
final readonly class LazySessionStartMiddleware implements MiddlewareInterface
{
	public function __construct(private SessionManagerInterface $session)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->isSessionlessPath($this->routePath($request))) {
			return $handler->handle($request);
		}

		if (!$this->session->isStarted()) {
			$this->start();
		}

		$response = $handler->handle($request);
		$this->session->save();

		return $response;
	}

	/**
	 * PHP refuses a session id that is too long or holds characters outside
	 * `[a-zA-Z0-9,-]`: session_start() fails and odan throws, and every page
	 * that starts a session answered a blank 500 until the visitor cleared the
	 * cookie (security scan, 2026-10-30 — Schemathesis sent fuzzed cookies). A
	 * cookie like that names no session we could have issued, so it is treated
	 * like an expired one: a fresh id, and the response replaces the cookie.
	 */
	private function start(): void
	{
		try {
			$this->session->start();
		} catch (SessionException $e) {
			if (session_status() === PHP_SESSION_ACTIVE) {
				throw $e;
			}
			session_id((string)session_create_id());
			$this->session->start();
		}
	}

	/**
	 * The request path with the install's base path removed. This middleware
	 * runs outside BasePathMiddleware, so the URI still carries the sub-path
	 * prefix; BasePath::resolve() is the computation that middleware uses, so
	 * both agree on where the route starts. When nothing resolves, the full
	 * path comes back and the anchored rules below don't match, which keeps
	 * the session: a little slower, never logged out.
	 */
	private function routePath(ServerRequestInterface $request): string
	{
		$path = $request->getUri()->getPath();

		return BasePath::strip(BasePath::resolve((string)($request->getServerParams()['SCRIPT_NAME'] ?? ''), $path), $path);
	}

	/**
	 * Paths that never touch session state, matched from the start of the
	 * route. Anchoring matters: an unanchored `imageworks/` also matched
	 * `/api/collections/features/imageworks/gallery`, the upload route of an
	 * object whose id is `imageworks`, which then ran without a session and
	 * failed the remember-me restore with a 500.
	 *
	 * Static assets are on the list for a second reason besides the lock: a
	 * started session sets the PHPSESSID cookie on the response, and a CDN
	 * never caches a response that carries Set-Cookie. Every core stylesheet
	 * and script behind `/api/assets/` (and every extension asset behind
	 * `/api/ext/{vendor}/{name}/assets/`) was reaching the origin on each
	 * first visit despite its one-year immutable cache header.
	 *
	 * Front-end URLs ending in an image extension are on it too: a Site
	 * Builder page that serves an image (a badge other sites embed, an OG
	 * card) otherwise opens a session, and sets a cookie, on every view of
	 * every page the image sits on. Admin and API paths keep their session
	 * whatever they end in.
	 */
	private function isSessionlessPath(string $path): bool
	{
		return str_starts_with($path, '/imageworks/')
			|| str_starts_with($path, '/api/assets/')
			|| preg_match('#^/api/ext/[^/]+/[^/]+/assets/#', $path) === 1
			|| (preg_match('#\.(svg|png|jpe?g|gif|webp|avif|ico)$#i', $path) === 1 && preg_match('#^/(admin|api)/#', $path) !== 1);
	}
}
