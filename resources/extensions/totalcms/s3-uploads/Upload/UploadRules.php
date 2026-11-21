<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Upload;

/**
 * The property's `settings.rules`, checked on the server. Same meaning as the
 * rules core upload fields take: sizes in KB, and file types and file names
 * as regular expressions where any one may match.
 */
final readonly class UploadRules
{
	/** 5 GiB: the most one PUT can carry on S3. */
	public const MAX_SINGLE_PUT = 5368709120;

	/**
	 * @param array<string,mixed> $rules
	 * @param int                 $size          bytes
	 * @param int                 $existingCount files the property already holds (s3depot)
	 *
	 * @return array{code:string, message:string}|null null when the file is acceptable
	 */
	public static function check(array $rules, string $name, int $size, string $mime, int $existingCount = 0, string $field = 's3file'): ?array
	{
		if ($size > self::MAX_SINGLE_PUT) {
			return self::refuse('s3.over_single_put', "{$name} is larger than 5 GB, the most a single upload can carry.");
		}

		$sizeRule = is_array($rules['size'] ?? null) ? $rules['size'] : [];
		$kb       = $size / 1024;
		$max      = self::number($sizeRule['max'] ?? null);
		$min      = self::number($sizeRule['min'] ?? null);

		if ($max !== null && $kb > $max) {
			return self::refuse('s3.too_large', "{$name} is " . self::formatSize($size) . '. The limit for this field is ' . self::formatSize((int)round($max * 1024)) . '.');
		}
		if ($min !== null && $kb < $min) {
			return self::refuse('s3.too_small', "{$name} is " . self::formatSize($size) . '. Files for this field must be at least ' . self::formatSize((int)round($min * 1024)) . '.');
		}

		if (!self::matchesAny($rules['filetype'] ?? null, $mime)) {
			return self::refuse('s3.filetype', "{$name} is not an accepted file type for this field.");
		}
		if (!self::matchesAny($rules['filename'] ?? null, $name)) {
			return self::refuse('s3.filename', "{$name} is not an accepted file name for this field.");
		}

		$countRule = is_array($rules['count'] ?? null) ? $rules['count'] : [];
		$countMax  = self::number($countRule['max'] ?? null);
		if ($field === 's3depot' && $countMax !== null && $existingCount >= $countMax) {
			return self::refuse('s3.count', 'This field holds at most ' . (int)$countMax . ' files.');
		}

		return null;
	}

	/** "46 MB", "1.5 KB", "999 B": 1024 steps, one decimal unless it is zero. */
	public static function formatSize(int $bytes): string
	{
		$value = (float)max(0, $bytes);
		$units = ['B', 'KB', 'MB', 'GB'];
		$unit  = 0;
		while ($value >= 1024 && $unit < count($units) - 1) {
			$value /= 1024;
			$unit++;
		}

		$text = $unit === 0 ? (string)(int)$value : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

		return $text . ' ' . $units[$unit];
	}

	/**
	 * True when there are no patterns, or any one matches. A pattern that does
	 * not compile matches nothing, so a broken rule refuses instead of allowing.
	 */
	private static function matchesAny(mixed $patterns, string $value): bool
	{
		$patterns = is_string($patterns) ? [$patterns] : (is_array($patterns) ? $patterns : []);
		$patterns = array_filter($patterns, fn (mixed $p): bool => is_string($p) && $p !== '');
		if ($patterns === []) {
			return true;
		}

		foreach ($patterns as $pattern) {
			if (@preg_match('#' . str_replace('#', '\#', $pattern) . '#', $value) === 1) {
				return true;
			}
		}

		return false;
	}

	private static function number(mixed $value): ?float
	{
		return is_numeric($value) ? (float)$value : null;
	}

	/** @return array{code:string, message:string} */
	private static function refuse(string $code, string $message): array
	{
		return ['code' => $code, 'message' => $message];
	}
}
