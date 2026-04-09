<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Formatter;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

class FileHelper
{
	/**
	 * Create an UploadedFileInterface from a local file path.
	 * Used by CLI import commands to pass files to services that expect PSR-7 uploads.
	 */
	public static function createUploadedFile(string $filePath): UploadedFileInterface
	{
		if (!file_exists($filePath)) {
			throw new \RuntimeException("File not found: {$filePath}");
		}

		$stream = Stream::create((string)file_get_contents($filePath));
		$size   = filesize($filePath);

		return new UploadedFile(
			$stream,
			$size !== false ? $size : 0,
			UPLOAD_ERR_OK,
			basename($filePath),
		);
	}
}
