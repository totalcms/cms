<?php

namespace TotalCMS\Action\Emergency;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Emergency cache clearing action that bypasses normal middleware and authentication.
 * This provides a way to clear caches when the normal admin interface is inaccessible due to errors.
 *
 * Publicly accessible so customers can fix their sites when cached errors prevent admin access.
 * Cache clearing is not a security-sensitive operation and helps prevent support requests.
 */
final readonly class EmergencyCacheClearAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private CacheManager $cacheManager,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			// Clear all caches including OPcache
			$result = $this->cacheManager->clearAllCaches();

			return $this->renderer->json($response, [
				'success'   => true,
				'message'   => 'Emergency cache clear completed',
				'cleared'   => $result,
				'timestamp' => date('Y-m-d H:i:s'),
				'note'      => 'All caches including OPcache have been cleared',
				'usage'     => 'This endpoint is available when the admin interface is inaccessible due to cached errors',
			]);
		} catch (\Throwable $e) {
			return $this->renderer->json($response, [
				'error'    => 'Failed to clear caches',
				'details'  => $e->getMessage(),
				'fallback' => 'Consider restarting Apache/Nginx if this endpoint also fails',
				'contact'  => 'If problems persist, contact TotalCMS support',
			], 500);
		}
	}
}
