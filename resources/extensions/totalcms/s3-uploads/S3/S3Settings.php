<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\S3;

/**
 * The extension's settings, cleaned once: every string trimmed, the prefix
 * reduced to plain segments, limits clamped. Everything else reads these
 * values and never the raw settings array.
 */
final readonly class S3Settings
{
	public const TTL_MIN     = 60;
	public const TTL_MAX     = 604800;
	public const TTL_DEFAULT = 3600;

	/**
	 * @param string             $endpoint      scheme + host (+ path), no trailing slash; '' for Amazon S3
	 * @param string             $prefix        '' or 'segment/…/'
	 * @param string             $publicBaseUrl CDN or custom domain, no trailing slash; '' for the bucket URL
	 * @param 'public'|'private' $visibility
	 * @param 'auto'|'path'|'virtual' $addressing
	 */
	public function __construct(
		public string $endpoint,
		public string $region,
		public string $bucket,
		public string $accessKey,
		public string $secretKey,
		public string $prefix,
		public string $publicBaseUrl,
		public string $visibility,
		public int $signedUrlTtl,
		public string $addressing,
	) {
	}

	/** @param array<string,mixed> $settings */
	public static function fromArray(array $settings): self
	{
		$text = fn (string $key, string $default = ''): string => is_scalar($settings[$key] ?? null) ? trim((string)$settings[$key]) : $default;

		$endpoint = rtrim($text('endpoint'), '/');
		if ($endpoint !== '' && !preg_match('#^https?://#i', $endpoint)) {
			$endpoint = 'https://' . $endpoint;
		}

		$visibility = $text('visibility', 'public');
		$addressing = $text('addressing', 'auto');
		$ttl        = $text('signedUrlTtl');

		return new self(
			endpoint: $endpoint,
			region: $text('region', 'us-east-1'),
			bucket: $text('bucket'),
			accessKey: $text('accessKey'),
			secretKey: $text('secretKey'),
			prefix: self::cleanPrefix($text('prefix')),
			publicBaseUrl: rtrim($text('publicBaseUrl'), '/'),
			visibility: $visibility === 'private' ? 'private' : 'public',
			signedUrlTtl: self::clampTtl(is_numeric($ttl) ? (int)$ttl : self::TTL_DEFAULT),
			addressing: in_array($addressing, ['path', 'virtual'], true) ? $addressing : 'auto',
		);
	}

	public static function clampTtl(int $seconds): int
	{
		return max(self::TTL_MIN, min(self::TTL_MAX, $seconds));
	}

	public function isConfigured(): bool
	{
		return $this->bucket !== '' && $this->accessKey !== '' && $this->secretKey !== '' && $this->region !== '';
	}

	public function isPrivate(): bool
	{
		return $this->visibility === 'private';
	}

	/** Bucket in the path (`host/bucket/key`) rather than in the hostname (`bucket.host/key`). */
	public function usePathStyle(): bool
	{
		return match ($this->addressing) {
			'path'    => true,
			'virtual' => false,
			// A dotted bucket name breaks the wildcard TLS certificate of a
			// virtual host, and most S3-compatible servers expect path style.
			default   => $this->endpoint !== '' || str_contains($this->bucket, '.'),
		};
	}

	/** Where the bucket answers, without a trailing slash. Append `/` and an encoded key. */
	public function bucketBaseUrl(): string
	{
		$endpoint = $this->endpoint !== '' ? $this->endpoint : 'https://s3.' . $this->region . '.amazonaws.com';

		if ($this->usePathStyle()) {
			return $endpoint . '/' . $this->bucket;
		}

		return (string)preg_replace('#^(https?://)#i', '$1' . $this->bucket . '.', $endpoint, 1);
	}

	/** The Host header for bucket requests: the hostname, plus the port when the URL names one. */
	public function host(): string
	{
		$parts = parse_url($this->bucketBaseUrl());
		$host  = is_array($parts) ? (string)($parts['host'] ?? '') : '';

		return is_array($parts) && isset($parts['port']) ? $host . ':' . $parts['port'] : $host;
	}

	private static function cleanPrefix(string $prefix): string
	{
		$segments = [];
		foreach (explode('/', $prefix) as $segment) {
			$segment = trim($segment);
			if ($segment !== '' && $segment !== '.' && $segment !== '..') {
				$segments[] = $segment;
			}
		}

		return $segments === [] ? '' : implode('/', $segments) . '/';
	}
}
