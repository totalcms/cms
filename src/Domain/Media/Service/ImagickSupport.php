<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

/**
 * Whether the Imagick extension can actually process images on this host.
 *
 * `extension_loaded('imagick')` answers "is the PHP extension present", which is
 * not the same question. A production host was found running an Imagick whose
 * underlying ImageMagick had NO coders registered at all —
 * `Imagick::queryFormats()` returned an empty array — so every read threw
 * `NoDecodeDelegateForThisImageFormat 'JPEG'`. The extension loaded fine, so
 * every `extension_loaded()` check in the codebase picked Imagick over a
 * perfectly good GD and the site could not render a single JPEG. The operator
 * saw a 404 and the log said "Unable to decode input", neither of which points
 * anywhere near a missing delegate.
 *
 * `queryFormats()` is the right probe for this. It is a cheap in-process call
 * and, unlike the HEIC case that motivated {@see HeicConverter::selfTest()}, it
 * does not under-report: an empty or JPEG-less result means genuinely unable.
 * It CAN over-report (ImageMagick advertises HEIC while libheif lacks the HEVC
 * decoder), which is why HEIC still needs a real decode — but that is a
 * different question from "can this Imagick do anything at all".
 */
final class ImagickSupport
{
	/**
	 * Formats ImageWorks needs before Imagick is worth choosing over GD.
	 *
	 * Deliberately the two that every site actually serves rather than the full
	 * Glide output set: a host missing WEBP degrades one format, a host missing
	 * JPEG is broken outright, and GD covers both.
	 */
	private const REQUIRED_FORMATS = ['JPEG', 'PNG'];

	/** @var list<string>|null Cached for the request; queryFormats() is not free. */
	private static ?array $formats = null;

	/**
	 * True when Imagick is loaded AND can handle the formats we rely on.
	 *
	 * Callers choosing between the imagick and gd drivers should use this rather
	 * than extension_loaded(), so a non-functional Imagick falls back instead of
	 * being selected and failing at decode time.
	 */
	public static function isUsable(): bool
	{
		$formats = self::formats();

		if ($formats === []) {
			return false;
		}

		foreach (self::REQUIRED_FORMATS as $required) {
			if (!in_array($required, $formats, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Formats this Imagick reports support for, uppercased as ImageMagick names
	 * them. Empty when the extension is absent, or present but non-functional.
	 *
	 * @return list<string>
	 */
	public static function formats(): array
	{
		if (self::$formats !== null) {
			return self::$formats;
		}

		if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
			return self::$formats = [];
		}

		try {
			$formats = \Imagick::queryFormats();
		} catch (\Throwable) {
			// A broken install can throw rather than return empty.
			return self::$formats = [];
		}

		return self::$formats = array_map(
			strtoupper(...),
			$formats,
		);
	}

	/** Clear the cached probe. Tests only — the result cannot change mid-request. */
	public static function reset(): void
	{
		self::$formats = null;
	}
}
