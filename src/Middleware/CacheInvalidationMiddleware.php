<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\CacheInvalidationSignal;

/**
 * Replays cache invalidation signals left by CLI processes.
 *
 * CLI scripts (e.g., processJobs.php) cannot clear web-process APCu.
 * This middleware checks for a signal file on each request and replays
 * any recorded invalidations through CacheManager in the web context.
 */
class CacheInvalidationMiddleware implements MiddlewareInterface
{
	public function __construct(
		private readonly CacheInvalidationSignal $signal,
		private readonly CacheManager $cacheManager,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->signal->hasSignal()) {
			$this->replayInvalidations();
		}

		return $handler->handle($request);
	}

	private function replayInvalidations(): void
	{
		$entries = $this->signal->consume();
		if ($entries === null) {
			return;
		}

		// Suppress signal writes during replay to avoid infinite recursion
		$this->cacheManager->setSuppressSignals(true);

		try {
			foreach ($entries as $entry) {
				if ($entry === 'full') {
					$this->cacheManager->clearAllCaches();

					// Full clear covers everything, no need to process further
					return;
				}

				if (str_starts_with($entry, 'pattern:')) {
					$pattern = substr($entry, 8);
					$this->cacheManager->clearByPatternAllBackends($pattern);
				} else {
					$this->cacheManager->clearData($entry);
				}
			}
		} finally {
			$this->cacheManager->setSuppressSignals(false);
		}
	}
}
