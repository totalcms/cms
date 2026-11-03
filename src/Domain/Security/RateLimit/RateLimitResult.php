<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\RateLimit;

/**
 * The outcome of one request against a rate limit. A disabled limit (zero or
 * less) is always allowed and reports a limit of 0.
 */
final readonly class RateLimitResult
{
	public function __construct(
		public bool $allowed,
		public int $limit,
		public int $remaining,
		public int $window,
		public int $retryAfter,
	) {
	}
}
