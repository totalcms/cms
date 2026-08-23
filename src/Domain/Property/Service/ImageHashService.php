<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

/**
 * Computes a short content hash for image data.
 *
 * Used as a cache-buster in ImageWorks URLs so that browsers and CDNs
 * refetch the rendered image when any field that could affect the output
 * (focal point, dimensions, watermark-embedded metadata, etc.) changes.
 */
final class ImageHashService
{
	/**
	 * Keys excluded from the hash input to avoid circular or always-changing
	 * values.
	 *
	 * `uploadDate` is excluded because it tracks WHEN the field was last
	 * written, not what it holds. Every upload already gets a unique filename
	 * (PropertyRepository::getUniqueFilename() appends a uniqid suffix), so
	 * `name` changes whenever new bytes arrive — a re-upload of the same file
	 * included. Feeding uploadDate in added no cache-busting the name didn't
	 * already provide, and cost stability: any write that rebuilt an untouched
	 * image field produced a fresh hash, needlessly busting ImageWorks crops
	 * and making two identical images (an empty one especially) compare
	 * unequal across installs.
	 *
	 * `updateDate` and `modifiedAt` match no field ImageData actually emits —
	 * kept as harmless defensive aliases.
	 */
	public const EXCLUDED_KEYS = ['hash', 'uploadDate', 'updateDate', 'modifiedAt'];

	/**
	 * Compute a short content hash from image data.
	 *
	 * @param array<string,mixed> $imageData
	 */
	public static function compute(array $imageData): string
	{
		foreach (self::EXCLUDED_KEYS as $key) {
			unset($imageData[$key]);
		}

		try {
			$json = json_encode(
				self::canonicalize($imageData),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
			);
		} catch (\JsonException) {
			return '';
		}

		return substr(sha1($json), 0, 8);
	}

	/**
	 * Recursively sort associative-array keys while preserving list order.
	 *
	 * @param array<mixed> $data
	 *
	 * @return array<mixed>
	 */
	private static function canonicalize(array $data): array
	{
		if (!array_is_list($data)) {
			ksort($data);
		}

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = self::canonicalize($value);
			}
		}

		return $data;
	}
}
