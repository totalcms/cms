<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Cache\Service\CacheBackends;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * One log line when a share URL's `v` no longer matches its record. The
 * usual cause is a template that changes a value the overlay draws (the
 * title, say) before calling cms.seo.head(): the page signs one card and the
 * image route rebuilds another, so the plain image is shared.
 *
 * The route is public and crawlers keep fetching share URLs that went stale
 * when a record was edited, so each collection, id and hash is logged at
 * most once a day.
 */
readonly class SocialImageMismatchLog
{
	private const TTL = 86400;

	public function __construct(
		private LoggerFactory $loggers,
		private CacheBackends $cache,
	) {
	}

	public function note(string $collection, string $id, string $hash): void
	{
		$key      = 'share-mismatch:' . sha1("{$collection}/{$id}/{$hash}");
		$backends = $this->cache->available();
		if ($this->cache->firstHit($backends, $key) !== null) {
			return;
		}

		$this->cache->storeFirst($backends, $key, 1, self::TTL);
		$this->loggers->channelLogger(LogChannel::ImageGenerator)
			->info(sprintf('Share image hash mismatch: %s/%s v=%s', $collection, $id, $hash));
	}
}
