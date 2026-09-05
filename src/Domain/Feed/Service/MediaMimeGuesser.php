<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Service;

/**
 * Guess an enclosure MIME type from a media URL's extension.
 *
 * An RSS `<enclosure>` carries a required `type`, and the only thing a feed
 * has to go on when the caller supplies a bare URL is the extension. Shared by
 * RssBuilder and FeedWriter so the two cannot drift into different answers for
 * the same file.
 *
 * A caller that knows better should say so — T3 image and file field values
 * already carry `mime`, and FeedWriter's hash form takes it directly.
 */
final class MediaMimeGuesser
{
	public const FALLBACK = 'application/octet-stream';

	public static function guess(string $media): string
	{
		$extension = strtolower(pathinfo(parse_url($media, PHP_URL_PATH) ?: $media, PATHINFO_EXTENSION));

		return match ($extension) {
			'mp3'          => 'audio/mpeg',
			'm4a'          => 'audio/mp4',
			'ogg', 'oga'   => 'audio/ogg',
			'wav'          => 'audio/wav',
			'flac'         => 'audio/flac',
			'mp4', 'm4v'   => 'video/mp4',
			'webm'         => 'video/webm',
			'mov'          => 'video/quicktime',
			'jpg', 'jpeg'  => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'avif'         => 'image/avif',
			'svg'          => 'image/svg+xml',
			'pdf'          => 'application/pdf',
			'epub'         => 'application/epub+zip',
			'zip'          => 'application/zip',
			default        => self::FALLBACK,
		};
	}
}
