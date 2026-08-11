<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Restores the Authorization header on hosts that strip it before PHP.
 *
 * Apache running PHP as CGI/FastCGI does not pass `Authorization` to the
 * script unless the vhost sets `CGIPassAuth On` (2.4.13+) or an .htaccess
 * rewrite exports it. The shipped public/.htaccess exports it as
 * REDIRECT_HTTP_AUTHORIZATION via the [E=HTTP_AUTHORIZATION:...] idiom; this
 * middleware copies that back onto the PSR-7 request so OAuthBearerMiddleware
 * and API-key auth see the credential the client actually sent.
 *
 * Without this, Bearer-authenticated requests silently degrade to anonymous —
 * observed live on a Stacks install 2026-08-10 (bogus Bearer token answered
 * 200/anonymous instead of 401).
 */
readonly class AuthorizationHeaderMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($request->getHeaderLine('Authorization') === '') {
			$server   = $request->getServerParams();
			$restored = (string)($server['REDIRECT_HTTP_AUTHORIZATION'] ?? $server['HTTP_AUTHORIZATION'] ?? '');
			if ($restored !== '') {
				$request = $request->withHeader('Authorization', $restored);
			}
		}

		return $handler->handle($request);
	}
}
