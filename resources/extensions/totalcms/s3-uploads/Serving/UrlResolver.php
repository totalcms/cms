<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Serving;

use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\S3\SigV4Signer;
use TotalCMS\Bundled\S3Uploads\Storage\Pointer;

/**
 * The three kinds of link to a stored file:
 *
 * - counted: a route on this site that counts the download and redirects.
 *   It never expires and survives a change of bucket or provider.
 * - public: the file's address at the bucket or CDN. Public buckets only.
 * - signed: a bucket link that works for a limited time. Private buckets.
 */
final readonly class UrlResolver
{
	public const ROUTE = '/api/ext/totalcms/s3-uploads';

	/** @param string $api the install's base path (`Config::$api`) */
	public function __construct(
		private S3Settings $settings,
		private S3Client $client,
		private string $api,
	) {
	}

	/** @param string|null $fileId one file of an s3depot */
	public function countedUrl(string $collection, string $id, string $property, ?string $fileId = null, bool $download = false): string
	{
		$segments = [$collection, $id, $property];
		if ($fileId !== null && $fileId !== '') {
			$segments[] = $fileId;
		}

		return rtrim($this->api, '/') . self::ROUTE . ($download ? '/download/' : '/stream/') . implode('/', array_map(rawurlencode(...), $segments));
	}

	/** Where anyone can read the key when the bucket is public. */
	public function publicUrl(string $key): string
	{
		if ($this->settings->publicBaseUrl !== '') {
			return $this->settings->publicBaseUrl . '/' . SigV4Signer::encodePath(ltrim($key, '/'));
		}

		return $this->client->objectUrl($key);
	}

	/**
	 * A link straight to the bucket: public, or signed when the bucket is private.
	 *
	 * @param int|null $expires seconds a signed link lasts; the setting when null
	 */
	public function directUrl(Pointer $pointer, ?int $expires = null): string
	{
		return $this->settings->isPrivate()
			? $this->client->presignGet($pointer->key, $this->lifetime($expires))
			: $this->publicUrl($pointer->key);
	}

	/** A signed link that makes the browser save the file under its original name. */
	public function downloadUrl(Pointer $pointer): string
	{
		return $this->client->presignGet($pointer->key, $this->settings->signedUrlTtl, $pointer->name);
	}

	/** Seconds a signed link from directUrl() lasts. */
	public function lifetime(?int $expires = null): int
	{
		return $expires === null ? $this->settings->signedUrlTtl : S3Settings::clampTtl($expires);
	}
}
