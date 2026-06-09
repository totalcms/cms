<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cache;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Output (fragment) cache behind the {% cache %} Twig tag. Stores rendered
 * HTML in CacheManager so an expensive block body is skipped on a hit.
 *
 * Invalidation is generational: each tag has a version counter
 * (fragver:{tag}); a fragment's storage key embeds the current versions of
 * its tags, so bumping a counter makes every fragment keyed on the old
 * version unreachable. Orphans expire by their own TTL.
 *
 * Safe by default: caching is bypassed for authenticated requests (so
 * access-group/member content is never stored or served cross-user) unless
 * the author opts in with shared=true. Cache-layer failures fall back to a
 * live render and never break the page.
 */
final class FragmentCache
{
	private const FRAG_PREFIX  = 'frag:';
	private const VER_PREFIX   = 'fragver:';
	private const VER_TTL      = 2592000; // 30 days — must outlive fragment TTLs

	public function __construct(
		private readonly CacheManager $cache,
		private readonly SessionInterface $session,
		private readonly int $defaultTtl = 3600,
		private readonly bool $enabled = true,
	) {
	}

	/**
	 * @param list<string>       $tags
	 * @param \Closure(): string $renderBody
	 */
	public function render(string $key, ?int $ttl, array $tags, bool $shared, \Closure $renderBody): string
	{
		if ($key === '' || !$this->enabled || $this->cache->isCacheDisabled()) {
			return $renderBody();
		}

		if (!$shared && $this->isAuthenticated()) {
			return $renderBody();
		}

		$storageKey = $this->storageKey($key, $tags);

		$cached = $this->safeGet($storageKey);
		if (is_string($cached)) {
			return $cached;
		}

		$html = $renderBody();
		$this->safeStore($storageKey, $html, $ttl ?? $this->defaultTtl);

		return $html;
	}

	public function bumpTag(string $tag): void
	{
		try {
			$current = $this->safeVersion($tag);
			$this->cache->storeData(self::VER_PREFIX . $tag, $current + 1, self::VER_TTL);
		} catch (\Throwable) {
			// best-effort; a missed bump only risks a stale fragment until TTL
		}
	}

	/** @param list<string> $tags */
	private function storageKey(string $key, array $tags): string
	{
		$suffix = '';
		foreach ($tags as $tag) {
			$tag = (string)$tag;
			$suffix .= '|' . $tag . '=' . $this->safeVersion($tag);
		}

		return $this->cache->applyDomainPrefix(self::FRAG_PREFIX . sha1($key . $suffix));
	}

	private function safeVersion(string $tag): int
	{
		try {
			return (int)($this->cache->getOperationalData(self::VER_PREFIX . $tag) ?? 0);
		} catch (\Throwable) {
			return 0;
		}
	}

	private function safeGet(string $storageKey): mixed
	{
		try {
			return $this->cache->getData($storageKey);
		} catch (\Throwable) {
			return null;
		}
	}

	private function safeStore(string $storageKey, string $html, int $ttl): void
	{
		try {
			$this->cache->storeData($storageKey, $html, $ttl);
		} catch (\Throwable) {
			// non-fatal
		}
	}

	private function isAuthenticated(): bool
	{
		try {
			return !empty($this->session->get(SessionKeys::AUTH_USER));
		} catch (\Throwable) {
			return false;
		}
	}
}
