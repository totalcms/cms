<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cache\Service;

/**
 * The four key/value backends in priority order (APCu → Redis → Memcached →
 * Filesystem) and the fan-out primitives CacheManager and IdentityCache are
 * built from.
 *
 * "Available" honors the operator's cache config. "Installed" only asks
 * whether the extension exists, for data that has to be cached whatever the
 * config says — the license verdict, which would otherwise hit the license
 * server on every request.
 */
final readonly class CacheBackends
{
	public function __construct(
		private APCuService $apcu,
		private RedisService $redis,
		private MemcachedService $memcached,
		private FilesystemService $filesystem,
	) {
	}

	public function apcu(): APCuService
	{
		return $this->apcu;
	}

	public function filesystem(): FilesystemService
	{
		return $this->filesystem;
	}

	/**
	 * Memory backends in priority order.
	 *
	 * @return list<CacheInterface>
	 */
	public function memory(bool $installed = false): array
	{
		return array_values(array_filter(
			[$this->apcu, $this->redis, $this->memcached],
			fn (CacheInterface $backend): bool => $installed ? $backend->isInstalled() : $backend->isAvailable(),
		));
	}

	/** The L2 network cache: Redis when available, else Memcached. */
	public function network(): ?CacheInterface
	{
		if ($this->redis->isAvailable()) {
			return $this->redis;
		}

		if ($this->memcached->isAvailable()) {
			return $this->memcached;
		}

		return null;
	}

	/**
	 * Every backend the config allows, memory first and the filesystem last.
	 *
	 * @return list<CacheInterface>
	 */
	public function available(): array
	{
		$backends = $this->memory();
		if ($this->filesystem->isAvailable()) {
			$backends[] = $this->filesystem;
		}

		return $backends;
	}

	/**
	 * The first non-null value across the backends, in order.
	 *
	 * @param list<CacheInterface> $backends
	 */
	public function firstHit(array $backends, string $key): mixed
	{
		foreach ($backends as $backend) {
			$result = $backend->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Write to the first backend that accepts it.
	 *
	 * @param list<CacheInterface> $backends
	 */
	public function storeFirst(array $backends, string $key, mixed $value, int $ttl): bool
	{
		foreach ($backends as $backend) {
			if ($backend->set($key, $value, $ttl)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete from every backend. True only when every delete succeeded, but
	 * one failure never stops the others — a stale entry left behind is the
	 * outcome this exists to prevent.
	 *
	 * @param list<CacheInterface> $backends
	 */
	public function deleteFrom(array $backends, string $key): bool
	{
		$success = true;
		foreach ($backends as $backend) {
			$success = $backend->delete($key) && $success;
		}

		return $success;
	}

	/**
	 * Clear a key pattern from every backend; same all-or-nothing report as
	 * {@see deleteFrom()}.
	 *
	 * @param list<CacheInterface> $backends
	 */
	public function clearPattern(array $backends, string $pattern): bool
	{
		$success = true;
		foreach ($backends as $backend) {
			$success = $backend->clearByPattern($pattern) && $success;
		}

		return $success;
	}
}
