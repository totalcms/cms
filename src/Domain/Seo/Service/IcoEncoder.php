<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

/**
 * Wraps a PNG in a one-image ICO container.
 *
 * `/favicon.ico` is requested by browsers and crawlers whether or not the head
 * lists an icon. Neither GD nor Glide writes ICO, but the container has
 * accepted PNG data since Windows Vista and every browser reads it, so the
 * 32px PNG ImageWorks already produces only needs a 6-byte header and one
 * 16-byte directory entry in front of it. No raster library involved, which
 * keeps it working on GD-only shared hosts.
 */
final class IcoEncoder
{
	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/** ICONDIR (6 bytes) + one ICONDIRENTRY (16 bytes). */
	private const HEADER_SIZE = 22;

	public static function fromPng(string $png): string
	{
		if (!str_starts_with($png, self::PNG_SIGNATURE) || strlen($png) < 24) {
			throw new \InvalidArgumentException('IcoEncoder needs PNG data');
		}

		// IHDR: width and height are the two big-endian 32-bit ints after the
		// 8-byte signature and the chunk length/type (bytes 16–23).
		/** @var array{w:int,h:int} $size */
		$size = unpack('Nw/Nh', substr($png, 16, 8));

		// The directory stores width and height in one byte each, with 0
		// meaning 256 — the largest size the classic format can name.
		$dimension = static fn (int $px): int => $px >= 256 ? 0 : $px;

		return pack('vvv', 0, 1, 1)
			. pack('CCCCvvVV', $dimension($size['w']), $dimension($size['h']), 0, 0, 1, 32, strlen($png), self::HEADER_SIZE)
			. $png;
	}
}
