<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Data;

/**
 * What an anonymous visitor may drop into one depot property, read from the
 * property's `publicUpload` setting by PublicUploadPolicy.
 */
final readonly class PublicUploadGrant
{
	/** @param list<string> $extensions Lowercase, no leading dot */
	public function __construct(
		public array $extensions,
		public int $maxFileBytes,
		public int $maxTotalBytes,
		public ?string $folder,
		public bool $comments,
	) {
	}
}
