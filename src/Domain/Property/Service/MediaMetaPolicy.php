<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

/**
 * Which keys of an uploaded image, gallery image, file or depot file a
 * metadata edit may change, and how an edit lands on the stored value.
 *
 * Descriptive keys (alt text, tags, a focal point, a download label) are
 * editable, as they are in the admin's edit dialogs. Keys that identify or
 * describe the stored bytes — name, size, MIME type, dimensions, hash,
 * palette, upload date, download count — never change through an edit: an
 * edit that sends one keeps the stored value. A file's `password` and
 * `protected` are editable only where the caller is allowed protection
 * settings (the REST meta routes, which the admin dialogs use), never over MCP.
 *
 * An edit merges into the stored value: `exif`, `focalpoint` and `credit`
 * merge one level deep, every other key replaces. `credit` is cleaned on the
 * way in: only its five keys, as trimmed strings, and its two links only when
 * they are http(s), since templates print them as links.
 */
final class MediaMetaPolicy
{
	/** @var list<string> */
	public const IMAGE_KEYS = ['alt', 'link', 'tags', 'featured', 'focalpoint', 'exif', 'credit'];

	/** @var list<string> The keys of an image's `credit`. */
	public const CREDIT_KEYS = ['author', 'authorUrl', 'source', 'sourceUrl', 'license'];

	/** Credit keys that hold a link. */
	private const CREDIT_URL_KEYS = ['authorUrl', 'sourceUrl'];

	/** @var list<string> */
	public const FILE_KEYS = ['download', 'comments', 'tags'];

	/** @var list<string> */
	public const PROTECTION_KEYS = ['password', 'protected'];

	/** Keys merged one level deep rather than replaced. */
	private const NESTED_KEYS = ['exif', 'focalpoint', 'credit'];

	/**
	 * The keys an edit may change for a field type (`image`, `gallery`,
	 * `file`, `depot`). Empty for any other type.
	 *
	 * @return list<string>
	 */
	public static function editableKeys(string $type, bool $protection = false): array
	{
		return match ($type) {
			'image', 'gallery' => self::IMAGE_KEYS,
			'file', 'depot'    => $protection ? [...self::FILE_KEYS, ...self::PROTECTION_KEYS] : self::FILE_KEYS,
			default            => [],
		};
	}

	/**
	 * The keys of $edit that would change something they may not: a
	 * non-editable key whose value differs from the stored one. Echoing a
	 * stored value back unchanged is not a change.
	 *
	 * @param array<string,mixed> $stored
	 * @param array<string,mixed> $edit
	 *
	 * @return list<string>
	 */
	public static function refusedKeys(string $type, array $stored, array $edit, bool $protection = false): array
	{
		$editable = self::editableKeys($type, $protection);
		$refused  = [];
		foreach ($edit as $key => $value) {
			$key = (string)$key;
			if (!in_array($key, $editable, true) && ($stored[$key] ?? null) !== $value) {
				$refused[] = $key;
			}
		}

		return $refused;
	}

	/**
	 * The stored item with $edit's editable keys laid over it. Any other key
	 * in $edit is ignored.
	 *
	 * @param array<string,mixed> $stored
	 * @param array<string,mixed> $edit
	 *
	 * @return array<string,mixed>
	 */
	public static function merge(string $type, array $stored, array $edit, bool $protection = false): array
	{
		foreach (self::editableKeys($type, $protection) as $key) {
			if (!array_key_exists($key, $edit)) {
				continue;
			}

			$stored[$key] = in_array($key, self::NESTED_KEYS, true) && is_array($edit[$key]) && is_array($stored[$key] ?? null)
				? array_merge($stored[$key], $edit[$key])
				: $edit[$key];
		}

		if (array_key_exists('credit', $stored)) {
			$stored['credit'] = self::cleanCredit($stored['credit']);
		}

		return $stored;
	}

	/**
	 * A credit as stored: the known keys only, trimmed strings, empty values
	 * dropped, and a link only when it is http or https.
	 *
	 * @return array<string,string>
	 */
	public static function cleanCredit(mixed $credit): array
	{
		$clean = [];
		foreach (self::CREDIT_KEYS as $key) {
			$value = is_array($credit) && is_scalar($credit[$key] ?? null) ? trim((string)$credit[$key]) : '';
			if (in_array($key, self::CREDIT_URL_KEYS, true) && preg_match('#^https?://#i', $value) !== 1) {
				$value = '';
			}
			if ($value !== '') {
				$clean[$key] = $value;
			}
		}

		return $clean;
	}
}
