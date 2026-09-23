<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Rendering\Utilities\TemplatePlaceholder;

/**
 * Generic service for generating values using autogen patterns.
 * Used by any field type that supports the autogen setting.
 */
readonly class AutogenService
{
	/**
	 * Maximum length of any single field value substituted into an autogen
	 * pattern. Generous for real titles/names, but caps rich-text fields
	 * (e.g. styletext) that would otherwise blow the generated ID past the
	 * filesystem's ~255-char filename limit. The special tokens (oid, uid,
	 * timestamp, date parts) are added after filtering, so uniqueness
	 * suffixes are never truncated.
	 */
	public const MAX_FIELD_LENGTH = 100;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
	) {
	}

	/**
	 * Generate a value using an autogen pattern (no slugification).
	 *
	 * @param string $pattern The autogen pattern (e.g., "${firstname} ${lastname}")
	 * @param string $collection The collection ID for OID counter
	 * @param array<string,mixed> $objectData Object data for field replacement
	 *
	 * @return string Generated value
	 */
	public function generate(string $pattern, string $collection, array $objectData): string
	{
		$data = $this->prepareReplacementData($collection, $objectData);

		return self::renderPattern($pattern, $data, fn (): int => $this->getNextOid($collection));
	}

	/**
	 * Generate a value with explicit OID count (for testing).
	 *
	 * @param string $pattern The autogen pattern
	 * @param array<string,mixed> $objectData Object data for field replacement
	 * @param int $oidCount Current OID count
	 *
	 * @return string Generated value
	 */
	public static function generateWithOidCount(string $pattern, array $objectData, int $oidCount): string
	{
		$data = self::prepareReplacementDataWithOid($objectData, $oidCount);

		return self::renderPattern($pattern, $data, fn (): int => $oidCount + 1);
	}

	/**
	 * Prepare replacement data including special variables.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function prepareReplacementData(string $collection, array $objectData): array
	{
		$data = self::filterObjectData($objectData);

		// Add special autogen variables
		$data['now']       = (string)(time() * 1000);
		$data['timestamp'] = date('Ymd\THis');
		$data['uuid']      = AutogenIdService::generateUuid();
		$data['uid']       = AutogenIdService::generateUid();
		$data['oid']       = (string)$this->getNextOid($collection);

		// Date components
		$data['currentyear']  = date('Y');
		$data['currentyear2'] = date('y');
		$data['currentmonth'] = date('m');
		$data['currentday']   = date('d');

		return $data;
	}

	/**
	 * Prepare replacement data with explicit OID count.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private static function prepareReplacementDataWithOid(array $objectData, int $oidCount): array
	{
		$data = self::filterObjectData($objectData);

		$data['now']       = (string)(time() * 1000);
		$data['timestamp'] = date('Ymd\THis');
		$data['uuid']      = AutogenIdService::generateUuid();
		$data['uid']       = AutogenIdService::generateUid();
		$data['oid']       = (string)($oidCount + 1);

		$data['currentyear']  = date('Y');
		$data['currentyear2'] = date('y');
		$data['currentmonth'] = date('m');
		$data['currentday']   = date('d');

		return $data;
	}

	/**
	 * Filter object data to only strings and numbers.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,string>
	 */
	private static function filterObjectData(array $objectData): array
	{
		$data = [];
		foreach ($objectData as $key => $value) {
			if (is_string($value)) {
				$data[$key] = mb_substr($value, 0, self::MAX_FIELD_LENGTH);
			} elseif (is_numeric($value)) {
				$data[$key] = mb_substr((string)$value, 0, self::MAX_FIELD_LENGTH);
			}
		}

		return $data;
	}

	/**
	 * Fill `${key}` placeholders from the object data. `${oid-000}` is the next
	 * object id zero-padded to the placeholder's width; `${uid-N}` a random id
	 * of N characters. Only the OID source differs between the two callers.
	 *
	 * @param array<string,mixed> $data
	 * @param callable(): int     $nextOid
	 */
	private static function renderPattern(string $pattern, array $data, callable $nextOid): string
	{
		return TemplatePlaceholder::render($pattern, function (string $key) use ($data, $nextOid): string {
			if (preg_match('/^oid-0+$/', $key)) {
				return str_pad((string)$nextOid(), strlen(substr($key, 4)), '0', STR_PAD_LEFT);
			}

			if (preg_match('/^uid-(\d+)$/', $key, $matches)) {
				return AutogenIdService::generateUid((int)$matches[1]);
			}

			return (string)($data[$key] ?? '');
		});
	}

	/**
	 * Get the next OID for the collection.
	 */
	private function getNextOid(string $collection): int
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData) {
			return 1;
		}

		return $collectionData->count + 1;
	}
}
