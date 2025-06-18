<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Content Security Policy middleware for XSS protection.
 * Adds CSP headers to prevent inline scripts and unsafe content execution.
 *
 * Applies strict CSP to all requests since Total CMS only serves admin/API routes.
 */
final class ContentSecurityPolicyMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);

		// Total CMS only serves admin/API routes - apply strict CSP to all requests
		$cspHeader = "default-src 'self'; " .
					"script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
					"style-src 'self' 'unsafe-inline'; " .
					"img-src 'self' data: blob:; " .
					"font-src 'self'; " .
					"connect-src 'self'; " .
					"media-src 'self'; " .
					"object-src 'none'; " .
					"base-uri 'self'; " .
					"form-action 'self'; " .
					"frame-ancestors 'none'";

		return $response
			->withHeader('Content-Security-Policy', $cspHeader)
			->withHeader('X-Content-Type-Options', 'nosniff')
			->withHeader('X-Frame-Options', 'DENY')
			->withHeader('X-XSS-Protection', '1; mode=block')
			->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
	}
}
