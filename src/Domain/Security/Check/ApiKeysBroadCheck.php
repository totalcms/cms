<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;

/** Names keys by `name` (or `id`). The key value is never read into a finding. */
class ApiKeysBroadCheck implements SecurityCheck
{
	private const ID            = 'api-keys-broad';
	private const DOCS          = 'operations/security#api-keys-broad';
	private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
	private const STALE_DAYS    = 180;
	private const UNUSED_DAYS   = 30;

	public function __construct(private readonly ApiKeyFetcher $keys)
	{
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function id(): string
	{
		return self::ID;
	}

	public function title(): string
	{
		return 'API keys';
	}

	public function run(SecurityContext $context): array
	{
		$keys = $this->keys->getAllKeys();
		$broad = [];
		$stale = [];

		foreach ($keys as $key) {
			if ($this->isBroadWriter($key)) {
				$broad[] = $this->label($key);
			}
			if ($this->isStale($key)) {
				$stale[] = $this->label($key);
			}
		}

		$findings = [];
		if ($broad !== []) {
			$findings[] = SecurityFinding::found(
				self::ID,
				Severity::Info,
				'API keys that can write anywhere',
				'These keys allow write methods on every path: ' . implode(', ', $broad) . '.',
				'Limit each key to the methods and paths its job needs.',
				self::DOCS,
			);
		}
		if ($stale !== []) {
			$findings[] = SecurityFinding::found(
				self::ID,
				Severity::Info,
				'Unused API keys',
				sprintf('These keys were never used and created more than %d days ago, or not used for %d days: ', self::UNUSED_DAYS, self::STALE_DAYS) . implode(', ', $stale) . '.',
				'Delete keys you no longer need.',
				self::DOCS,
			);
		}

		return $findings === [] ? [SecurityFinding::ok(self::ID, 'No broad or unused API keys')] : $findings;
	}

	/** Empty methods allow nothing (ApiKeyPermissionChecker::allowsMethod), so only listed write methods count. */
	private function isBroadWriter(ApiKeyData $key): bool
	{
		$methods = array_map(
			fn (mixed $m): string => strtoupper((string)$m),
			(array)($key->scopes['methods'] ?? []),
		);
		if (array_intersect(self::WRITE_METHODS, $methods) === []) {
			return false;
		}

		// Same normalization the enforcement uses (ApiKeyGrants::paths): `/*` and `/api/*` become `*`; `/` is only the API root.
		return in_array('*', ApiKeyGrants::paths($key), true);
	}

	private function isStale(ApiKeyData $key): bool
	{
		$now = time();
		if ($key->lastUsed !== null && $key->lastUsed !== '') {
			$used = strtotime($key->lastUsed);

			return $used !== false && $used < $now - self::STALE_DAYS * 86400;
		}
		$created = strtotime($key->created);

		return $created !== false && $created < $now - self::UNUSED_DAYS * 86400;
	}

	private function label(ApiKeyData $key): string
	{
		return $key->name !== '' ? $key->name : $key->id;
	}
}
