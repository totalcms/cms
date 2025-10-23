<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Development;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\OPcacheService;

/**
 * Development mode middleware that flushes OPcache on each request when dev mode is active.
 * This ensures PHP code changes are immediately visible during development.
 */
class DevModeMiddleware implements MiddlewareInterface
{
	private static ?bool $devModeChecked = null;
	private static bool $isDevModeActive = false;

	public function __construct(
		private readonly DevModeManager $devModeManager,
		private readonly OPcacheService $opcacheService,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Check dev mode status only once per request (performance optimization)
		if (self::$devModeChecked === null) {
			self::$isDevModeActive = $this->devModeManager->isDevModeActive();
			self::$devModeChecked  = true;
		}

		// Flush OPcache if dev mode is active and OPcache is available
		if (self::$isDevModeActive && $this->opcacheService->isAvailable()) {
			$this->opcacheService->clear();
		}

		return $handler->handle($request);
	}
}
