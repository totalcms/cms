<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\S3;

/** One entry of a bucket listing. */
final readonly class ListedObject
{
	/** @param int $lastModified Unix time */
	public function __construct(
		public string $key,
		public int $size,
		public int $lastModified,
		public string $etag,
	) {
	}
}
