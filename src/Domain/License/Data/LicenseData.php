<?php

namespace TotalCMS\Domain\License\Data;

/**
 * License validation response data.
 */
readonly class LicenseData
{
	public const CACHE_KEY         = 'license_validation';
	public const CACHE_TTL         = 24 * 60 * 60;      // 24 hours - when to try refreshing
	public const CACHE_STORAGE_TTL = 7 * 24 * 60 * 60;  // 7 days - how long to keep in cache

	public function __construct(
		public bool $valid,
		public bool $trial,
		public string $domain,
		public string $edition,
		public string $message,
		public ?string $validationToken,
		public bool $updatesValid,
		public ?int $trialDaysRemaining,
		public bool $dnsVerified = false,
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
			valid               : $response['valid'] ?? false,
			trial               : $response['trial'] ?? false,
			domain              : $response['domain'] ?? '',
			edition             : $response['edition'] ?? 'unknown',
			message             : $response['message'] ?? '',
			validationToken     : $response['validationToken'] ?? null,
			updatesValid        : $response['updatesValid'] ?? false,
			trialDaysRemaining  : $response['trialDaysRemaining'] ?? null,
			dnsVerified         : $response['dnsVerified'] ?? false,
			timestamp           : time(),
		);
	}

	/**
	 * Check if cache is still valid.
	 */
	public function isCacheValid(): bool
	{
		return (time() - $this->timestamp) < self::CACHE_TTL;
	}

	/**
	 * Convert to array for caching.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'valid'              => $this->valid,
			'trial'              => $this->trial,
			'domain'             => $this->domain,
			'edition'            => $this->edition,
			'message'            => $this->message,
			'validationToken'    => $this->validationToken,
			'updatesValid'       => $this->updatesValid,
			'trialDaysRemaining' => $this->trialDaysRemaining,
			'dnsVerified'        => $this->dnsVerified,
			'timestamp'          => $this->timestamp,
		];
	}
}
