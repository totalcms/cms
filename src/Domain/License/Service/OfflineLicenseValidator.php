<?php

namespace TotalCMS\Domain\License\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Repository\OfflineLicenseRepository;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Offline license validation service.
 *
 * Validates offline license files (JWT) for air-gapped installations.
 * Offline licenses are signed with RS256 (asymmetric) - the public key
 * is embedded here for verification.
 *
 * License files are stored in: tcms-data/.system/{domain}-offline-license.key
 * The domain-specific filename prevents accidental use of wrong license.
 */
class OfflineLicenseValidator
{
	/**
	 * RSA public key for verifying offline license signatures.
	 * This key is safe to distribute - it can only verify, not sign.
	 */
	private const RSA_PUBLIC_KEY = <<<'EOD'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsHnTCdEdIu0+/n497Okb
QSw2Mpq4eZOwuhz4BZet+J0sVjuzCC420u46KG0RHZ9YmPkg6ySYClCbtemziG74
wH03dhJ7E0Q6W88QyHUU0n+5iljrKFE+OZB9GHAOQ6kT1ZvGv3HojqMtiY12OP7i
xssTBDJus6eKX8Mp2MiRwkpJBZCMz7XDvBP8tr82SqBG/nTGJsRhjFeFWFwJdtap
UvDp+obFif1KpXxbih3T3zAwZxhkVwnCsaZuVvmFSFZMqZIcWvLl3+YEZkZUuEBg
MMvhypRRgR3Iibqr/WoGLbaGUgXwDpwJqsZ98WysaLDV+6Wko1VhgDoF+eadOduE
4QIDAQAB
-----END PUBLIC KEY-----
EOD;

	private readonly LoggerInterface $logger;

	/** @var LicenseData|false|null In-memory cache: null=not checked, false=no license, LicenseData=valid */
	private LicenseData|false|null $cachedLicense = null;

	/** @var array<string,mixed>|false|null In-memory cache for details */
	private array|false|null $cachedDetails = null;

	public function __construct(
		private readonly OfflineLicenseRepository $repository,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('license.log')->createLogger('license');
	}

	/**
	 * Check if an offline license file exists.
	 * Also checks the upload location and moves file if found.
	 */
	public function hasOfflineLicense(): bool
	{
		$exists = $this->repository->exists();
		$this->logger->debug('Checking for offline license file', [
			'exists'       => $exists,
			'expectedFile' => $this->repository->getExpectedFilename(),
		]);

		return $exists;
	}

	/**
	 * Validate offline license and return LicenseData if valid.
	 * Returns null if no offline license exists or validation fails.
	 */
	public function validate(): ?LicenseData
	{
		// Return cached result if already validated this request
		if ($this->cachedLicense !== null) {
			return $this->cachedLicense === false ? null : $this->cachedLicense;
		}

		$this->logger->debug('Attempting offline license validation');

		$token = $this->repository->read();

		if ($token === null) {
			$this->logger->debug('No offline license file found');
			$this->cachedLicense = false;

			return null;
		}

		$this->logger->debug('Offline license file read successfully', [
			'tokenLength' => strlen($token),
		]);

		try {
			$licenseData = $this->validateToken($token);
			$this->logger->debug('Offline license validated successfully', [
				'domain'       => $licenseData->domain,
				'edition'      => $licenseData->edition,
				'updatesValid' => $licenseData->updatesValid,
			]);

			$this->cachedLicense = $licenseData;

			return $licenseData;
		} catch (\Exception $e) {
			$this->logger->warning('Offline license validation failed', [
				'error' => $e->getMessage(),
			]);

			$this->cachedLicense = false;

			// Invalid token - return null to fall through to online validation
			return null;
		}
	}

	/**
	 * Validate an offline license token and return LicenseData.
	 *
	 * @throws \Exception If token is invalid
	 */
	public function validateToken(string $token): LicenseData
	{
		// Decode and verify JWT with RSA public key
		$decoded = JWT::decode($token, new Key(self::RSA_PUBLIC_KEY, 'RS256'));

		// Verify this is an offline license
		if (!isset($decoded->type) || $decoded->type !== 'offline') {
			throw new \InvalidArgumentException('Not an offline license token');
		}

		// Verify domain matches
		$currentDomain = $this->config->domain;
		if (!isset($decoded->domain) || $decoded->domain !== $currentDomain) {
			throw new \InvalidArgumentException(
				"Domain mismatch: license is for '{$decoded->domain}', but current domain is '{$currentDomain}'"
			);
		}

		// Check if edition is eligible (Pro or Enterprise only)
		$edition = $decoded->edition ?? 'unknown';
		if (!in_array($edition, ['pro', 'enterprise'], true)) {
			throw new \InvalidArgumentException("Edition '{$edition}' is not eligible for offline license");
		}

		// Check updates validity against version release date
		$updatesValidUntil = $decoded->updatesValidUntil ?? null;
		$updatesValid      = $this->checkUpdatesValid($updatesValidUntil);

		return new LicenseData(
			valid              : true,
			trial              : false,
			domain             : $decoded->domain,
			edition            : $edition,
			message            : $this->getStatusMessage($updatesValid, $updatesValidUntil),
			validationToken    : null, // Offline licenses don't need validation tokens
			updatesValid       : $updatesValid,
			trialDaysRemaining : null,
			dnsVerified        : true, // Offline licenses are inherently verified
			timestamp          : time(),
		);
	}

	/**
	 * Check if updates are valid based on version release date.
	 */
	private function checkUpdatesValid(?string $updatesValidUntil): bool
	{
		if ($updatesValidUntil === null) {
			return false;
		}

		$versionDate = Version::date();

		// If we can't verify the version date (signature invalid), assume updates are valid
		// This is a fail-safe - we don't want to block legitimate users
		if ($versionDate === null) {
			return true;
		}

		try {
			$updatesExpiry = new \DateTime($updatesValidUntil);
			$releaseDate   = new \DateTime($versionDate);

			return $releaseDate <= $updatesExpiry;
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Get status message based on updates validity.
	 */
	private function getStatusMessage(bool $updatesValid, ?string $updatesValidUntil): string
	{
		if ($updatesValid) {
			return 'Offline license active. Updates valid until ' . ($updatesValidUntil ?? 'unknown') . '.';
		}

		$versionDate = Version::date();
		if ($versionDate !== null && $updatesValidUntil !== null) {
			return "Offline license active. Updates expired on {$updatesValidUntil}. "
				. "This version ({$versionDate}) requires renewal.";
		}

		return 'Offline license active. Updates status unknown.';
	}

	/**
	 * Get the expected filename for the offline license.
	 * Useful for displaying to users where to place the file.
	 */
	public function getExpectedFilename(): string
	{
		return $this->repository->getExpectedFilename();
	}

	/**
	 * Get the expected upload directory for the offline license.
	 */
	public function getExpectedDirectory(): string
	{
		return $this->repository->getUploadDirectory();
	}

	/**
	 * Get offline license details for display (without full validation).
	 * Returns null if no offline license exists.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getDetails(): ?array
	{
		// Return cached result if already retrieved this request
		if ($this->cachedDetails !== null) {
			return $this->cachedDetails === false ? null : $this->cachedDetails;
		}

		$this->logger->debug('Getting offline license details');

		$token = $this->repository->read();

		if ($token === null) {
			$this->logger->debug('No offline license file found for details');
			$this->cachedDetails = false;

			return null;
		}

		try {
			$decoded = JWT::decode($token, new Key(self::RSA_PUBLIC_KEY, 'RS256'));

			$currentDomain = $this->config->domain;
			$domainMatch   = isset($decoded->domain) && $decoded->domain === $currentDomain;

			$this->logger->debug('Offline license details retrieved', [
				'licenseDomain' => $decoded->domain ?? 'unknown',
				'currentDomain' => $currentDomain,
				'domainMatch'   => $domainMatch,
			]);

			$updatesValidUntil = $decoded->updatesValidUntil ?? null;
			$updatesValid      = $this->checkUpdatesValid($updatesValidUntil);

			$this->cachedDetails = [
				'valid'             => $domainMatch,
				'domain'            => $decoded->domain ?? 'unknown',
				'domainMatch'       => $domainMatch,
				'currentDomain'     => $currentDomain,
				'edition'           => $decoded->edition ?? 'unknown',
				'licenseKey'        => $decoded->licenseKey ?? 'unknown',
				'updatesValidUntil' => $updatesValidUntil,
				'updatesValid'      => $updatesValid,
				'issuedAt'          => $decoded->issuedAt ?? null,
				'type'              => $decoded->type ?? 'unknown',
			];

			return $this->cachedDetails;
		} catch (\Exception $e) {
			$this->logger->warning('Failed to get offline license details', [
				'error' => $e->getMessage(),
			]);

			$this->cachedDetails = [
				'valid' => false,
				'error' => $e->getMessage(),
			];

			return $this->cachedDetails;
		}
	}
}
