<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for `file.downloaded`: a file was served as an attachment from a
 * `/download/...` route, after its protection (access groups, password)
 * passed. Inline streams (`/stream/...`) do not fire it; a media player makes
 * many range requests per play.
 *
 * `protected` is true when the file is gated by access groups or a password.
 */
readonly class FileDownloadedPayload extends EventPayload
{
	public function __construct(
		public string $collection,
		public string $id,
		public string $property,
		public string $filename,
		public string $subpath = '',
		public bool $protected = false,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection' => $this->collection,
			'id'         => $this->id,
			'property'   => $this->property,
			'filename'   => $this->filename,
			'subpath'    => $this->subpath,
			'protected'  => $this->protected,
		];
	}
}
