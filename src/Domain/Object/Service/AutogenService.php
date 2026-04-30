<?php

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

		return $this->replacePlaceholders($pattern, $data, $collection);
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

		return self::replacePlaceholdersWithOid($pattern, $data, $oidCount);
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
				$data[$key] = $value;
			} elseif (is_numeric($value)) {
				$data[$key] = (string)$value;
			}
		}

		return $data;
	}

	/**
	 * Replace placeholders in the pattern.
	 *
	 * @param array<string,mixed> $data
	 */
	private function replacePlaceholders(string $pattern, array $data, string $collection): string
	{
		return TemplatePlaceholder::render($pattern, function (string $key) use ($data, $collection): string {
			if (preg_match('/^oid-0+$/', $key)) {
				$zeros         = substr($key, 4);
				$paddingLength = strlen($zeros);
				$oidValue      = $this->getNextOid($collection);

				return str_pad((string)$oidValue, $paddingLength, '0', STR_PAD_LEFT);
			}

			return (string)($data[$key] ?? '');
		});
	}

	/**
	 * Replace placeholders with explicit OID count.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function replacePlaceholdersWithOid(string $pattern, array $data, int $oidCount): string
	{
		return TemplatePlaceholder::render($pattern, function (string $key) use ($data, $oidCount): string {
			if (preg_match('/^oid-0+$/', $key)) {
				$zeros         = substr($key, 4);
				$paddingLength = strlen($zeros);
				$oidValue      = $oidCount + 1;

				return str_pad((string)$oidValue, $paddingLength, '0', STR_PAD_LEFT);
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
