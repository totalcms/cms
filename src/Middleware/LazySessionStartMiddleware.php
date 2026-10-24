<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

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
		if ($this->isSessionlessPath(self::routePath($request))) {
			return $handler->handle($request);
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$response = $handler->handle($request);
		$this->session->save();

		return $response;
	}

	/**
	 * The request path with the install's base path removed. This middleware
	 * runs outside BasePathMiddleware, so the URI still carries the sub-path
	 * prefix; BasePath::resolve() is the computation that middleware uses, so
	 * both agree on where the route starts. When nothing resolves, the full
	 * path comes back and the anchored rules below don't match, which keeps
	 * the session: a little slower, never logged out.
	 */
	private static function routePath(ServerRequestInterface $request): string
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
