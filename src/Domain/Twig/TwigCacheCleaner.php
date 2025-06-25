<?php

namespace TotalCMS\Domain\Twig;

/**
 * Enhanced Twig cache cleaner with multi-backend support.
 * Delegates to TwigCacheManager for comprehensive cache clearing.
 */
final class TwigCacheCleaner
{
	public function __construct(
		private TwigCacheManager $cacheManager,
	) {
	}

	/**
	 * Clear all Twig caches including filesystem, OPcache, Redis, and Memcached.
	 * 
	 * @return bool True if all available caches were cleared successfully
	 */
	public function deleteCache(): bool
	{
		return $this->cacheManager->clearAllCaches();
	}
}
