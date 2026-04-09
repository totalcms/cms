<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Data;

/**
 * Information about an available update.
 */
readonly class UpdateInfo
{
	public function __construct(
		public bool $available,
		public string $version,
		public string $releaseDate,
		public string $severity,
		public string $changelog,
		public string $buildHash,
		public string $downloadUrl,
		public bool $updatesValid = true,
		public ?string $updatesExpireDate = null,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromApiResponse(array $data, bool $updatesValid = true, ?string $updatesExpireDate = null): self
	{
		return new self(
			available: (bool)($data['available'] ?? false),
			version: (string)($data['version'] ?? ''),
			releaseDate: (string)($data['releaseDate'] ?? ''),
			severity: (string)($data['severity'] ?? 'patch'),
			changelog: (string)($data['changelog'] ?? ''),
			buildHash: (string)($data['buildHash'] ?? ''),
			downloadUrl: (string)($data['downloadUrl'] ?? ''),
			updatesValid: $updatesValid,
			updatesExpireDate: $updatesExpireDate,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'available'         => $this->available,
			'version'           => $this->version,
			'releaseDate'       => $this->releaseDate,
			'severity'          => $this->severity,
			'changelog'         => $this->changelog,
			'buildHash'         => $this->buildHash,
			'downloadUrl'       => $this->downloadUrl,
			'updatesValid'      => $this->updatesValid,
			'updatesExpireDate' => $this->updatesExpireDate,
		];
	}
}
