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

	/**
	 * Ceiling on ImageMagick's heap pixel cache, in bytes. Past it, ImageMagick
	 * stages pixels in a memory-mapped file and then on disk instead of RAM.
	 *
	 * ImageMagick allocates outside PHP's memory manager, so `memory_limit`
	 * never sees it — and its own default on a 4 GB host is 1 GB per process.
	 * Measured on production (ImageMagick 6.9 Q16, PHP 8.4): resizing a
	 * 6000x4000 photo to 1200px peaked at 268 MB per process unlimited, 86 MB
	 * at these limits, ~10-20% slower, byte-identical output. A 12 MP photo
	 * (~96 MB of pixels) still fits under MAP_LIMIT and peaks the same either
	 * way; the cap is for full-size camera originals. A handful of concurrent
	 * renders at the unlimited figure is enough to push a small host into swap.
	 *
	 * What this does NOT change: retained memory. glibc returned the large
	 * allocations after each render either way. This bounds the peak only.
	 */
	private const MEMORY_LIMIT = 64 * 1024 * 1024;

	/**
	 * Ceiling on memory-mapped pixel cache, in bytes. It must be bounded too or
	 * the MEMORY limit is moot: a 128/256 MB MEMORY/MAP pair measured the same
	 * 242 MB peak as no limit at all.
	 */
	private const MAP_LIMIT = 128 * 1024 * 1024;

	/** @var list<string>|null Cached for the request; queryFormats() is not free. */
	private static ?array $formats = null;

	/** Resource limits are process-wide in ImageMagick; apply once per request. */
	private static bool $limited = false;

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

		// Every Imagick caller that goes through the capability probe gets
		// bounded memory without having to remember to ask for it.
		self::limitResources();

		return self::$formats = array_map(
			strtoupper(...),
			$formats,
		);
	}

	/**
	 * Cap ImageMagick's in-memory and memory-mapped pixel cache. Call before
	 * constructing an \Imagick anywhere that does not go through formats().
	 *
	 * Only ever lowers a limit: a host whose policy.xml is already stricter
	 * keeps its value. Never throws — an Imagick that refuses the call renders
	 * exactly as it did before.
	 */
	public static function limitResources(): void
	{
		if (self::$limited) {
			return;
		}
		self::$limited = true;

		if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
			return;
		}

		try {
			foreach ([
				\Imagick::RESOURCETYPE_MEMORY => self::MEMORY_LIMIT,
				\Imagick::RESOURCETYPE_MAP    => self::MAP_LIMIT,
			] as $type => $limit) {
				$current = (int)\Imagick::getResourceLimit($type);
				if ($current <= 0 || $current > $limit) {
					\Imagick::setResourceLimit($type, $limit);
				}
			}
		} catch (\Throwable) {
			// Unbounded is how every install ran before this existed.
		}
	}

	/** Clear the cached probe. Tests only — the result cannot change mid-request. */
	public static function reset(): void
	{
		self::$formats = null;
		self::$limited = false;
	}
}
