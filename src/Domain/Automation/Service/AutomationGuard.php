<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;

/**
 * Auto-disable guard for repeatedly-failing automations — mirrors
 * ExtensionGuard. Counts consecutive failures per automation in a rolling
 * window; on Production (never Development) it signals auto-disable once the
 * threshold is reached, so a broken cron/event handler can't fail (and email)
 * forever.
 */
final readonly class AutomationGuard
{
	public function __construct(
		private EnvironmentResolver $env,
		private CacheManager $cache,
		private int $threshold = 5,
		private int $windowSeconds = 300,
	) {
	}

	/**
	 * Record a failure. Returns true when the automation should be auto-disabled
	 * (Production only, threshold reached within the window). Never true in
	 * Development/preview — there, failures stay loud and the automation keeps
	 * running so the author can debug.
	 */
	public function recordFailure(string $automationId): bool
	{
		$key   = $this->cacheKey($automationId);
		$count = $this->cache->getData($key);
		$count = (is_int($count) ? $count : 0) + 1;
		$this->cache->storeData($key, $count, $this->windowSeconds);

		return $this->env->isQuarantineAllowed() && $count >= $this->threshold;
	}

	/**
	 * Clear the failure counter — call on a successful run and on manual
	 * re-enable so the automation gets a fresh start.
	 */
	public function reset(string $automationId): void
	{
		$this->cache->clearData($this->cacheKey($automationId));
	}

	/**
	 * Whether handler errors should be surfaced loudly (dev/preview) vs.
	 * contained (prod).
	 */
	public function shouldSurfaceErrors(): bool
	{
		return $this->env->shouldSurfaceErrors();
	}

	private function cacheKey(string $automationId): string
	{
		return 'auto_fail_' . md5($automationId);
	}
}
