<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Property\Data\SlugData;

/**
 * Service for generating automatic IDs using autogen patterns.
 * Delegates to AutogenService and adds slugification for valid ID format.
 */
readonly class AutogenIdService
{
	public function __construct(
		private AutogenService $autogenService,
	) {
	}

	/**
	 * Generate an ID using autogen pattern from schema settings.
	 *
	 * Examples:
	 * - "${title}-${oid-00000}" - Title with padded OID
	 * - "${currentyear}-${oid-00000}" - Year-based IDs (2025-00001)
	 * - "${currentyear2}${currentmonth}${currentday}-${oid-000}" - Date-based (251107-001)
	 *
	 * Reserved names: now, timestamp, uuid, uid, oid, currentyear, currentyear2, currentmonth, currentday
	 *
	 * @param string $pattern The autogen pattern (e.g., "${title}-${oid-00000}")
	 * @param string $collection The collection ID for OID counter
	 * @param array<string,mixed> $objectData Object data for field replacement
	 *
	 * @return string Generated ID (slugified)
	 */
	public function generateId(string $pattern, string $collection, array $objectData): string
	{
		$generatedValue = $this->autogenService->generate($pattern, $collection, $objectData);

		return SlugData::slugify($generatedValue);
	}

	/**
	 * Generate an ID with explicit OID count for testing.
	 *
	 * @param string $pattern The autogen pattern (e.g., "${title}-${oid-00000}")
	 * @param array<string,mixed> $objectData Object data for field replacement
	 * @param int $oidCount Current OID count
	 *
	 * @return string Generated ID (slugified)
	 */
	public static function generateIdWithOidCount(string $pattern, array $objectData, int $oidCount): string
	{
		$generatedValue = AutogenService::generateWithOidCount($pattern, $objectData, $oidCount);

		return SlugData::slugify($generatedValue);
	}

	/**
	 * Generate a real UUID (RFC 4122 format).
	 *
	 * @return string UUID string
	 */
	public static function generateUuid(): string
	{
		$data    = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0F | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3F | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Generate a short random UID similar to JavaScript version.
	 *
	 * @return string 7-character alphanumeric string
	 */
	public static function generateUid(): string
	{
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$uid        = '';
		for ($i = 0; $i < 7; $i++) {
			$uid .= $characters[random_int(0, strlen($characters) - 1)];
		}

		return $uid;
	}
}
