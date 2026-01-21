<?php

namespace TotalCMS\Support;

class Version
{
	/**
	 * HMAC secret for verifying version.json signature.
	 * This prevents users from manually editing the date to bypass update checks.
	 */
	private const HMAC_SECRET = 'T0t4lCMS-V3rs10n-S1gn4tur3-K3y-2025';

	/** @var array<string,mixed>|null */
	private static ?array $data = null;

	private static ?bool $valid = null;

	/**
	 * Load and cache version data from version.json.
	 *
	 * @return array<string,mixed>
	 */
	private static function load(): array
	{
		if (self::$data !== null) {
			return self::$data;
		}

		$jsonFile = __DIR__ . '/../../version.json';
		$txtFile = __DIR__ . '/../../version.txt';

		// Try version.json first
		if (file_exists($jsonFile)) {
			$content = file_get_contents($jsonFile);
			if ($content !== false) {
				$decoded = json_decode($content, true);
				if (is_array($decoded)) {
					self::$data = $decoded;

					return self::$data;
				}
			}
		}

		// Fall back to version.txt for backwards compatibility
		if (file_exists($txtFile)) {
			$content = file_get_contents($txtFile);
			if ($content !== false) {
				$versionString = trim($content);
				// Parse "3.1.3-5e3c5139" format
				if (preg_match('/^(\d+\.\d+\.\d+)(?:-([a-f0-9]+))?$/', $versionString, $matches)) {
					self::$data = [
						'version' => $matches[1],
						'build' => $matches[2] ?? 'unknown',
						'date' => null,
						'signature' => null,
					];

					return self::$data;
				}
			}
		}

		self::$data = [
			'version' => 'unknown',
			'build' => 'unknown',
			'date' => null,
			'signature' => null,
		];

		return self::$data;
	}

	/**
	 * Verify the HMAC signature to ensure version.json hasn't been tampered with.
	 */
	public static function isValid(): bool
	{
		if (self::$valid !== null) {
			return self::$valid;
		}

		$data = self::load();

		// If no signature present (legacy version.txt), consider invalid for date purposes
		if (empty($data['signature']) || empty($data['version']) || empty($data['date'])) {
			self::$valid = false;

			return false;
		}

		// Verify HMAC: signature = HMAC-SHA256(version|date, secret)
		$payload = $data['version'] . '|' . $data['date'];
		$expectedSignature = hash_hmac('sha256', $payload, self::HMAC_SECRET);

		self::$valid = hash_equals($expectedSignature, $data['signature']);

		return self::$valid;
	}

	/**
	 * Get the full version string (e.g., "3.0.47-baee5e0e").
	 */
	public static function get(): string
	{
		$data = self::load();
		$version = $data['version'] ?? 'unknown';
		$build = $data['build'] ?? 'unknown';

		if ($version === 'unknown') {
			return 'unknown';
		}

		return $version . '-' . $build;
	}

	/**
	 * Get just the semantic version number (e.g., "3.0.47").
	 */
	public static function number(): string
	{
		$data = self::load();

		return $data['version'] ?? '3.0.0';
	}

	/**
	 * Get just the build/commit hash (e.g., "baee5e0e").
	 */
	public static function build(): string
	{
		$data = self::load();

		return $data['build'] ?? 'unknown';
	}

	/**
	 * Get the release date (e.g., "2025-01-12").
	 * Returns null if version.json is missing, invalid, or signature verification fails.
	 */
	public static function date(): ?string
	{
		if (! self::isValid()) {
			return null;
		}

		$data = self::load();

		return $data['date'] ?? null;
	}

	/**
	 * Get formatted version for Sentry releases (e.g., "totalcms@3.0.47").
	 */
	public static function formatted(): string
	{
		return 'totalcms@' . self::number();
	}

	/**
	 * Generate HMAC signature for version data.
	 * Used by build scripts to create valid version.json files.
	 */
	public static function generateSignature(string $version, string $date): string
	{
		$payload = $version . '|' . $date;

		return hash_hmac('sha256', $payload, self::HMAC_SECRET);
	}

	/**
	 * Reset cached data (useful for testing).
	 */
	public static function reset(): void
	{
		self::$data = null;
		self::$valid = null;
	}
}
