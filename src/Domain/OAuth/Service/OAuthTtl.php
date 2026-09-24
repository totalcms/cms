<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

/**
 * Converts the OAuth lifetime settings (PHP DateInterval specs such as
 * `PT1H` or `P30D`) to seconds, for the caches that must outlive the
 * tokens they track: the access-token revocation list and the
 * refresh-token replay detector. A revoked jti that falls out of the
 * cache before its token expires makes the token valid again, so these
 * TTLs must follow the configured lifetimes rather than the defaults.
 */
final class OAuthTtl
{
	/**
	 * Months and years are measured from the Unix epoch, which overstates
	 * no lifetime by more than a day — erring long is the safe direction
	 * for a revocation cache. An empty or invalid spec returns $fallback.
	 */
	public static function seconds(mixed $spec, int $fallback): int
	{
		if (!is_string($spec) || $spec === '') {
			return $fallback;
		}

		try {
			$seconds = (new \DateTimeImmutable('@0'))->add(new \DateInterval($spec))->getTimestamp();
		} catch (\Exception) {
			return $fallback;
		}

		return $seconds > 0 ? $seconds : $fallback;
	}
}
