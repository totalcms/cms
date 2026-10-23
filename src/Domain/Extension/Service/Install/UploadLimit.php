<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

use TotalCMS\Infrastructure\Filesystem\FileUtils;

/**
 * The largest extension zip this server will accept: the smaller of PHP's
 * upload_max_filesize and post_max_size. Shared by the install panel's help
 * text and the install action's "too large" error so both name one number.
 */
final class UploadLimit
{
	/**
	 * Human-readable limit (e.g. "8 MB"), or '' when neither ini setting is a
	 * real limit (0 or -1 = unlimited).
	 */
	public static function describe(): string
	{
		$limits = array_filter([
			FileUtils::iniSizeToBytes((string)ini_get('upload_max_filesize')),
			FileUtils::iniSizeToBytes((string)ini_get('post_max_size')),
		], static fn (int $b): bool => $b > 0);

		return $limits === [] ? '' : FileUtils::formatBytes(min($limits), 0);
	}
}
