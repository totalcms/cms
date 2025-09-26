<?php

namespace TotalCMS\Domain\License\Data;

/**
 * License validation response data.
 */
readonly class LicenseData
{
	/** @param array<string> $testingDomains */
	public function __construct(
		public bool $valid,
		public string $edition,
		public string $mainDomain,
		public bool $updatesValid,
		public ?string $updatesExpireDate,
		public string $allowedVersion,
		public array $testingDomains,
		public string $message,
		public ?string $validationToken,
		public bool $dnsVerified,
		public ?string $dnsRecord,
		public ?string $verificationToken,
		public bool $trialActive,
		public ?string $trialExpiresDate,
		public ?int $trialDaysRemaining,
		public int $timestamp = 0,
	) {
	}

	/**
	 * Create from API response array.
	 *
	 * @param array<string,mixed> $response
	 */
	public static function fromApiResponse(array $response): self
	{
		return new self(
			valid              : $response['valid'] ?? false,
			edition            : $response['edition'] ?? 'unknown',
			mainDomain         : $response['main_domain'] ?? '',
			updatesValid       : $response['updates_valid'] ?? false,
			updatesExpireDate  : $response['updates_expire_date'] ?? null,
			allowedVersion     : $response['allowed_version'] ?? '',
			testingDomains     : $response['testing_domains'] ?? [],
			message            : $response['message'] ?? '',
			validationToken    : $response['validation_token'] ?? null,
			dnsVerified        : $response['dns_verified'] ?? false,
			dnsRecord          : $response['dns_record'] ?? null,
			verificationToken  : $response['verification_token'] ?? null,
			trialActive        : $response['trial_active'] ?? false,
			trialExpiresDate   : $response['trial_expires_date'] ?? null,
			trialDaysRemaining : $response['trial_days_remaining'] ?? null,
			timestamp          : time(),
		);
	}

	/**
	 * Check if cache is still valid (24 hours).
	 */
	public function isCacheValid(): bool
	{
		return (time() - $this->timestamp) < (24 * 60 * 60); // 24 hours
	}
}
