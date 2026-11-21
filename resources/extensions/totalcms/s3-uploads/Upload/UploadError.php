<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Upload;

/**
 * An upload request that was refused. The message is the exact text the
 * editor sees; `$errorCode` (`s3.too_large`, …) is for scripts and tests.
 */
final class UploadError extends \RuntimeException
{
	/** @param int $status the HTTP status to answer with */
	public function __construct(
		public readonly string $errorCode,
		public readonly int $status,
		string $message,
	) {
		parent::__construct($message);
	}
}
