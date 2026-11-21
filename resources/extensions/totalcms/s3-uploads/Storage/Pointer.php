<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Storage;

/**
 * What an s3file property stores: where the file is in the bucket and what
 * the bucket said about it. Never a URL — links are built from the current
 * settings when a page renders, so a bucket or CDN change needs no data fix.
 *
 * REST, MCP and imports can write any JSON into the property, so a stored
 * value is untrusted: fromArray() answers null for anything that is not a
 * pointer, and callers still check the key against the prefix before use.
 */
final readonly class Pointer
{
	private const NAME_MAX_BYTES = 255;

	/**
	 * @param string     $id         the 8-hex segment of the key
	 * @param string     $name       the original file name, for display and downloads
	 * @param float|null $duration   seconds, for audio and video only
	 * @param string     $uploadedAt ISO 8601, UTC
	 */
	public function __construct(
		public string $id,
		public string $key,
		public string $name,
		public int $size,
		public string $mime,
		public string $etag,
		public ?float $duration,
		public string $uploadedAt,
		public string $uploadedBy,
	) {
	}

	public static function fromArray(mixed $value): ?self
	{
		if (!is_array($value)) {
			return null;
		}

		$id  = $value['id'] ?? null;
		$key = $value['key'] ?? null;
		if (!is_string($key) || $key === '' || !is_string($id) || preg_match('/^[0-9a-f]{8}\z/', $id) !== 1) {
			return null;
		}

		$text     = fn (string $field): string => is_scalar($value[$field] ?? null) ? (string)$value[$field] : '';
		$duration = $value['duration'] ?? null;

		return new self(
			id: $id,
			key: $key,
			name: $text('name') !== '' ? self::cleanName($text('name')) : basename($key),
			size: is_numeric($value['size'] ?? null) ? max(0, (int)$value['size']) : 0,
			mime: $text('mime') !== '' ? $text('mime') : 'application/octet-stream',
			// Pointers saved before the quotes were dropped still carry them.
			etag: trim($text('etag'), '"'),
			duration: is_numeric($duration) && (float)$duration > 0 ? (float)$duration : null,
			uploadedAt: $text('uploadedAt'),
			uploadedBy: $text('uploadedBy'),
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$data = [
			'id'   => $this->id,
			'key'  => $this->key,
			'name' => $this->name,
			'size' => $this->size,
			'mime' => $this->mime,
			'etag' => $this->etag,
		];
		if ($this->duration !== null) {
			$data['duration'] = $this->duration;
		}

		return $data + ['uploadedAt' => $this->uploadedAt, 'uploadedBy' => $this->uploadedBy];
	}

	/** A browser's file name made safe to store and show: no control characters, at most 255 bytes. */
	public static function cleanName(string $name): string
	{
		$name = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $name));
		$name = mb_strcut($name, 0, self::NAME_MAX_BYTES, 'UTF-8');

		return $name === '' ? 'file' : $name;
	}
}
