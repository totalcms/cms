<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Domain\License\Data\LicenseData;

/**
 * Cached data that belongs to one domain and must never be shared with another
 * install, whatever `cache.domainScoped` says: the license verdict and
 * password reset tokens. Neither is content, so neither is skipped in devmode
 * or when cache reads are disabled for the process.
 *
 * The license is cached whatever the operator's cache config says. Hitting the
 * license server on every request is a rate-limit cascade waiting to happen,
 * so it goes to the first *installed* memory backend and always to disk, where
 * it survives memory eviction and restarts.
 */
final class IdentityCache
{
	public const PREFIX_PASSWORD_RESET = 'password_reset';

	public function __construct(
		private readonly CacheBackends $backends,
		private readonly string $prefix,
	) {
	}

	public function storeLicense(string $key, mixed $data, int $ttl): bool
	{
		$domainKey = $this->key($key);

		// Stored as a plain array: the filesystem backend unserializes with
		// allowed_classes:false, which would turn a LicenseData object into
		// __PHP_Incomplete_Class and break every cache read.
		$arrayData = $data instanceof LicenseData ? $data->toArray() : $data;

		$memoryStored     = $this->backends->storeFirst($this->backends->memory(installed: true), $domainKey, $arrayData, $ttl);
		$filesystemStored = $this->backends->filesystem()->setMandatory($domainKey, $arrayData, $ttl);

		return $memoryStored || $filesystemStored;
	}

	public function getLicense(string $key): ?LicenseData
	{
		$domainKey = $this->key($key);
		$result    = $this->backends->firstHit($this->backends->memory(installed: true), $domainKey)
			?? $this->backends->filesystem()->getMandatory($domainKey);

		if (is_array($result) && isset($result['valid'], $result['domain'])) {
			return LicenseData::fromArray($result);
		}

		// Entries written before the array form was introduced.
		if ($result instanceof LicenseData) {
			return $result;
		}

		return null;
	}

	/** Clears every installed backend and the disk copy, whatever the config says. */
	public function clearLicense(string $key): bool
	{
		$domainKey     = $this->key($key);
		$memoryCleared = $this->backends->deleteFrom($this->backends->memory(installed: true), $domainKey);

		return $this->backends->filesystem()->deleteMandatory($domainKey) && $memoryCleared;
	}

	/**
	 * One layer only: a reset token is read once and cleared, so warming a
	 * second tier buys nothing.
	 *
	 * @param array<string,mixed> $data
	 */
	public function storePasswordReset(string $key, array $data, int $ttl): bool
	{
		return $this->backends->storeFirst($this->backends->available(), $this->resetKey($key), $data, $ttl);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getPasswordReset(string $key): ?array
	{
		$result = $this->backends->firstHit($this->backends->available(), $this->resetKey($key));

		return is_array($result) ? $result : null;
	}

	public function clearPasswordReset(string $key): bool
	{
		return $this->backends->deleteFrom($this->backends->available(), $this->resetKey($key));
	}

	private function key(string $key): string
	{
		return $this->prefix . ':' . $key;
	}

	private function resetKey(string $key): string
	{
		return $this->key(self::PREFIX_PASSWORD_RESET . ':' . $key);
	}
}
