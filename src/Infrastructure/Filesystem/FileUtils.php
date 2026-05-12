<?php

declare(strict_types=1);

namespace TotalCMS\Infrastructure\Filesystem;

class FileUtils
{
	public static function fileSizeString(int $size): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$unit  = 0;

		while ($size >= 1024) {
			$size /= 1024;
			$unit++;
		}

		return sprintf('%.1f %s', $size, $units[$unit]);
	}
}
