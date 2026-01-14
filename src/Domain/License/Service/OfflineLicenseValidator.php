<?php

namespace TotalCMS\Domain\License\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Offline license validation service.
 *
 * Validates offline license files (JWT) for air-gapped installations.
 * Offline licenses are signed with RS256 (asymmetric) - the public key
 * is embedded here for verification.
 */
readonly class OfflineLicenseValidator
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

	private const LICENSE_FILE = 'tcms-data/offline-license.key';

	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Check if an offline license file exists.
	 */
	public function hasOfflineLicense(): bool
	{
		return file_exists($this->getLicenseFilePath());
	}

	/**
	 * Validate offline license and return LicenseData if valid.
	 * Returns null if no offline license exists or validation fails.
	 */
	public function validate(): ?LicenseData
	{
		$filePath = $this->getLicenseFilePath();

		if (!file_exists($filePath)) {
			return null;
		}

		$token = file_get_contents($filePath);
		if ($token === false || trim($token) === '') {
			return null;
		}

		try {
			return $this->validateToken(trim($token));
		} catch (\Exception) {
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
		$updatesValid = $this->checkUpdatesValid($updatesValidUntil);

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
			$releaseDate = new \DateTime($versionDate);

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
	 * Get the full path to the offline license file.
	 */
	private function getLicenseFilePath(): string
	{
		$basePath = $this->config->basePath ?? '';

		return rtrim($basePath, '/') . '/' . self::LICENSE_FILE;
	}

	/**
	 * Get offline license details for display (without full validation).
	 * Returns null if no offline license exists.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getDetails(): ?array
	{
		$filePath = $this->getLicenseFilePath();

		if (!file_exists($filePath)) {
			return null;
		}

		$token = file_get_contents($filePath);
		if ($token === false || trim($token) === '') {
			return null;
		}

		try {
			$decoded = JWT::decode(trim($token), new Key(self::RSA_PUBLIC_KEY, 'RS256'));

			$currentDomain = $this->config->domain;
			$domainMatch = isset($decoded->domain) && $decoded->domain === $currentDomain;

			$updatesValidUntil = $decoded->updatesValidUntil ?? null;
			$updatesValid = $this->checkUpdatesValid($updatesValidUntil);

			return [
				'valid' => $domainMatch,
				'domain' => $decoded->domain ?? 'unknown',
				'domainMatch' => $domainMatch,
				'currentDomain' => $currentDomain,
				'edition' => $decoded->edition ?? 'unknown',
				'licenseKey' => $decoded->licenseKey ?? 'unknown',
				'updatesValidUntil' => $updatesValidUntil,
				'updatesValid' => $updatesValid,
				'issuedAt' => $decoded->issuedAt ?? null,
				'type' => $decoded->type ?? 'unknown',
			];
		} catch (\Exception $e) {
			return [
				'valid' => false,
				'error' => $e->getMessage(),
			];
		}
	}
}
