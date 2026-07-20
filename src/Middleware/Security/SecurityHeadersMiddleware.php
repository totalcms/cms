<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers for admin-facing HTML routes (admin, auth, setup).
 *
 * Scope is deliberately conservative — only headers that cannot break
 * admin functionality:
 *
 *  - Clickjacking: `frame-ancestors 'self'` / `X-Frame-Options: SAMEORIGIN`.
 *    Must be 'self', not 'none' — Depot and File fields embed
 *    `/admin/filelinks` in a same-origin iframe dialog.
 *  - MIME sniffing: `X-Content-Type-Options: nosniff`. Every admin-group
 *    responder sets an explicit Content-Type.
 *  - Referrer leakage: `strict-origin-when-cross-origin`.
 *
 * A full CSP (script-src/connect-src/...) is NOT emitted here: it would
 * need allowances for the Sentry browser SDK, video-embed previews, and
 * anything third-party extensions load into admin pages. Tune those
 * before widening the policy.
 *
 * Not applied to public/builder routes — whether a customer's site may
 * be framed is the site's decision, not the CMS's.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);

		return $response
			->withHeader('Content-Security-Policy', "frame-ancestors 'self'")
			->withHeader('X-Frame-Options', 'SAMEORIGIN')
			->withHeader('X-Content-Type-Options', 'nosniff')
			->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
	}
}
