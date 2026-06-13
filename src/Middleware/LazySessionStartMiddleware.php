<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Odan\Session\SessionManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
final class LazySessionStartMiddleware implements MiddlewareInterface
{
	public function __construct(private readonly SessionManagerInterface $session)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->isSessionlessPath($request->getUri()->getPath())) {
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
	 * Paths that never touch session state. Matched with a leading-boundary
	 * regex so it still holds when the app is installed under a sub-path
	 * (this middleware runs outside BasePathMiddleware, so the path may carry
	 * a base-path prefix).
	 */
	private function isSessionlessPath(string $path): bool
	{
		return preg_match('#(^|/)imageworks/#', $path) === 1;
	}
}
