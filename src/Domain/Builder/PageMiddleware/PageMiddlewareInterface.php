<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\PageMiddleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;

/**
 * Per-page middleware contract — runs against a builder-page match before
 * the page template renders.
 *
 * Implementations either:
 *  - Return null to allow the chain to continue (or, after the last entry,
 *    fall through to the page render).
 *  - Return a Response to short-circuit the chain. Auth gates return 302
 *    redirects, rate-limit middleware returns 429, etc. The first non-null
 *    response wins; subsequent middleware do not run.
 *
 * Why not PSR-15: Slim's `RequestHandlerInterface` "next" plumbing is
 * heavyweight for a synchronous "first to return wins" chain that doesn't
 * need request mutation between handlers. A PSR-15 adapter can be layered
 * later if extensions need to share middleware between page and route
 * contexts.
 */
interface PageMiddlewareInterface
{
	/**
	 * Run for the given page match. Return a Response to short-circuit the
	 * chain (and the page render). Return null to proceed.
	 */
	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface;
}
