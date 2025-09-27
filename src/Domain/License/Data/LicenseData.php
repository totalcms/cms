<?php

namespace TotalCMS\Domain\License\Data;

/**
 * License validation response data.
 */
readonly class LicenseData
{
	public const CACHE_TTL = 24 * 60 * 60; // 24 hours
	public function __construct(
		public bool $valid,
		public bool $trial,
		public string $domain,
		public string $edition,
		public string $message,
		public ?string $validationToken,
		public bool $updatesValid,
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
			valid               : $response['valid'] ?? false,
			trial               : $response['trial'] ?? false,
			domain              : $response['domain'] ?? '',
			edition             : $response['edition'] ?? 'unknown',
			message             : $response['message'] ?? '',
			validationToken     : $response['validation_token'] ?? null,
			updatesValid        : $response['updates_valid'] ?? false,
			trialDaysRemaining  : $response['trial_days_remaining'] ?? null,
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
			'valid'                 => $this->valid,
			'trial'                 => $this->trial,
			'domain'                => $this->domain,
			'edition'               => $this->edition,
			'message'               => $this->message,
			'validation_token'      => $this->validationToken,
			'updates_valid'         => $this->updatesValid,
			'trial_days_remaining'  => $this->trialDaysRemaining,
			'timestamp'             => $this->timestamp,
		];
	}
}
