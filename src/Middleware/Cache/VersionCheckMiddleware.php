<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;

/**
 * Clears all caches when the app version changes (e.g. after a Total CMS update).
 */
readonly class VersionCheckMiddleware implements MiddlewareInterface
{
	public function __construct(
		private CacheManager $cacheManager,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$this->cacheManager->clearIfVersionChanged();

		return $handler->handle($request);
	}
}
