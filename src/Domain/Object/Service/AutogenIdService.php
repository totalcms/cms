<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Property\Data\SlugData;

/**
 * Service for generating automatic IDs using autogen patterns.
 */
final readonly class AutogenIdService
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
	) {
	}

	/**
	 * Generate an ID using autogen pattern from schema settings.
	 *
	 * @param string $pattern The autogen pattern (e.g., "${title}-${oid-00000}")
	 * @param string $collection The collection ID for OID counter
	 * @param array<string,mixed> $objectData Object data for field replacement
	 *
	 * @return string Generated ID
	 */
	public function generateId(string $pattern, string $collection, array $objectData): string
	{
		// Prepare data for placeholder replacement
		$data = $this->prepareReplacementData($collection, $objectData);

		// Replace placeholders in the pattern
		$generatedId = $this->replacePlaceholders($pattern, $data, $collection);

		// Slugify the result to ensure valid ID format
		return SlugData::slugify($generatedId);
	}

	/**
	 * Generate an ID with explicit OID count for testing.
	 *
	 * @param string $pattern The autogen pattern (e.g., "${title}-${oid-00000}")
	 * @param array<string,mixed> $objectData Object data for field replacement
	 * @param int $oidCount Current OID count
	 *
	 * @return string Generated ID
	 */
	public static function generateIdWithOidCount(string $pattern, array $objectData, int $oidCount): string
	{
		// Prepare data for placeholder replacement (without collection lookup)
		$data = self::prepareReplacementDataWithOid($objectData, $oidCount);

		// Replace placeholders in the pattern
		$generatedId = self::replacePlaceholdersWithOid($pattern, $data, $oidCount);

		// Slugify the result to ensure valid ID format
		return SlugData::slugify($generatedId);
	}

	/**
	 * Prepare replacement data including special variables.
	 *
	 * @param string $collection
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function prepareReplacementData(string $collection, array $objectData): array
	{
		// Start with object data (filter to strings only like JavaScript version)
		$data = array_filter($objectData, fn ($value) => is_string($value));

		// Add special autogen variables
		$data['now']       = (string)(time() * 1000); // JavaScript Date.now() equivalent
		$data['timestamp'] = date('Ymd\THis'); // ISO format without colons/dashes
		$data['uuid']      = self::generateUuid();
		$data['uid']       = self::generateUid();
		$data['oid']       = (string)$this->getNextOid($collection);

		return $data;
	}

	/**
	 * Replace placeholders in the pattern.
	 *
	 * @param string $pattern
	 * @param array<string,mixed> $data
	 * @param string $collection
	 *
	 * @return string
	 */
	private function replacePlaceholders(string $pattern, array $data, string $collection): string
	{
		return preg_replace_callback('/\$\{([^}]+)\}/', function ($matches) use ($data, $collection) {
			$key = $matches[1];

			// Handle OID with zero-padding: oid-00000
			if (preg_match('/^oid-0+$/', $key)) {
				$zeros         = substr($key, 4); // Get the zero pattern (e.g., "00000")
				$paddingLength = strlen($zeros);
				$oidValue      = $this->getNextOid($collection);

				return str_pad((string)$oidValue, $paddingLength, '0', STR_PAD_LEFT);
			}

			// Standard placeholder replacement
			return $data[$key] ?? '';
		}, $pattern) ?? '';
	}

	/**
	 * Get the next OID for the collection.
	 *
	 * @param string $collection
	 *
	 * @return int
	 */
	private function getNextOid(string $collection): int
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
			return 1;
		}

		// Return the next OID (current count + 1)
		return $collectionData->count + 1;
	}

	/**
	 * Generate a real UUID (RFC 4122 format).
	 *
	 * @return string UUID string
	 */
	public static function generateUuid(): string
	{
		// Generate UUID v4 (random)
		$data    = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0F | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3F | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Generate a short random UID similar to JavaScript version.
	 *
	 * @return string 7-character alphanumeric string
	 */
	public static function generateUid(): string
	{
		// Generate random string similar to Math.random().toString(36).substring(2,9)
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$uid        = '';
		for ($i = 0; $i < 7; $i++) {
			$uid .= $characters[random_int(0, strlen($characters) - 1)];
		}

		return $uid;
	}

	/**
	 * Prepare replacement data with explicit OID count.
	 *
	 * @param array<string,mixed> $objectData
	 * @param int $oidCount
	 *
	 * @return array<string,mixed>
	 */
	private static function prepareReplacementDataWithOid(array $objectData, int $oidCount): array
	{
		// Start with object data (filter to strings only like JavaScript version)
		$data = array_filter($objectData, fn ($value) => is_string($value));

		// Add special autogen variables
		$data['now']       = (string)(time() * 1000); // JavaScript Date.now() equivalent
		$data['timestamp'] = date('Ymd\THis'); // ISO format without colons/dashes
		$data['uuid']      = self::generateUuid();
		$data['uid']       = self::generateUid();
		$data['oid']       = (string)($oidCount + 1);

		return $data;
	}

	/**
	 * Replace placeholders with explicit OID count.
	 *
	 * @param string $pattern
	 * @param array<string,mixed> $data
	 * @param int $oidCount
	 *
	 * @return string
	 */
	private static function replacePlaceholdersWithOid(string $pattern, array $data, int $oidCount): string
	{
		return preg_replace_callback('/\$\{([^}]+)\}/', function ($matches) use ($data, $oidCount) {
			$key = $matches[1];

			// Handle OID with zero-padding: oid-00000
			if (preg_match('/^oid-0+$/', $key)) {
				$zeros         = substr($key, 4); // Get the zero pattern (e.g., "00000")
				$paddingLength = strlen($zeros);
				$oidValue      = $oidCount + 1;

				return str_pad((string)$oidValue, $paddingLength, '0', STR_PAD_LEFT);
			}

			// Standard placeholder replacement
			return $data[$key] ?? '';
		}, $pattern) ?? '';
	}
}
