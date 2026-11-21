<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\S3;

/**
 * AWS Signature Version 4, for request headers and for presigned URLs.
 *
 * Pure: no I/O and no clock. The caller passes the time, so every signature
 * can be reproduced in a test. URLs handed in carry an already encoded path
 * (build them with encodePath()).
 */
final readonly class SigV4Signer
{
	public const EMPTY_SHA256     = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

	private const ALGORITHM   = 'AWS4-HMAC-SHA256';
	private const MAX_EXPIRES = 604800;

	public function __construct(
		private string $accessKey,
		private string $secretKey,
		private string $region,
		private string $service = 's3',
	) {
	}

	/**
	 * Sign a request the server sends itself.
	 *
	 * @param array<string,string> $headers     headers to send and sign (Host is taken from the URL)
	 * @param string               $payloadHash hex SHA-256 of the body; EMPTY_SHA256 for none
	 *
	 * @return array<string,string> $headers plus x-amz-date, x-amz-content-sha256 (S3 only) and Authorization
	 */
	public function signHeaders(string $method, string $url, array $headers, string $payloadHash, \DateTimeImmutable $now): array
	{
		$now                   = $now->setTimezone(new \DateTimeZone('UTC'));
		$headers['x-amz-date'] = $now->format('Ymd\THis\Z');
		if ($this->service === 's3') {
			$headers['x-amz-content-sha256'] = $payloadHash;
		}

		$signed    = self::canonicalHeaders($headers, self::hostOf($url));
		$canonical = $this->canonicalRequest($method, $url, $headers, $payloadHash);

		$headers['Authorization'] = self::ALGORITHM
			. ' Credential=' . $this->accessKey . '/' . $this->scope($now)
			. ', SignedHeaders=' . implode(';', array_keys($signed))
			. ', Signature=' . $this->signature($canonical, $now);

		return $headers;
	}

	/**
	 * A URL that carries its own signature, good for $expires seconds.
	 *
	 * @param array<string,string> $signedHeaders headers the eventual request must send with exactly these values (Host is always signed)
	 * @param array<string,string> $extraQuery    query parameters to add and sign
	 *
	 * @throws \InvalidArgumentException when $expires is outside 1–604800
	 */
	public function presign(string $method, string $url, array $signedHeaders, int $expires, \DateTimeImmutable $now, array $extraQuery = []): string
	{
		if ($expires < 1 || $expires > self::MAX_EXPIRES) {
			throw new \InvalidArgumentException('A presigned URL lasts between 1 and ' . self::MAX_EXPIRES . ' seconds.');
		}

		$now    = $now->setTimezone(new \DateTimeZone('UTC'));
		$signed = self::canonicalHeaders($signedHeaders, self::hostOf($url));
		$query  = $extraQuery + [
			'X-Amz-Algorithm'     => self::ALGORITHM,
			'X-Amz-Credential'    => $this->accessKey . '/' . $this->scope($now),
			'X-Amz-Date'          => $now->format('Ymd\THis\Z'),
			'X-Amz-Expires'       => (string)$expires,
			'X-Amz-SignedHeaders' => implode(';', array_keys($signed)),
		];

		$canonical = $this->canonicalRequest($method, $url, $signedHeaders, self::UNSIGNED_PAYLOAD, $query);
		$base      = (string)strtok($url, '?');

		return $base . '?' . self::canonicalQuery($url, $query) . '&X-Amz-Signature=' . $this->signature($canonical, $now);
	}

	/**
	 * @param array<string,string> $headers headers that take part in the signature (Host is added from the URL)
	 * @param array<string,string> $query   query parameters on top of the URL's own
	 */
	public function canonicalRequest(string $method, string $url, array $headers, string $payloadHash, array $query = []): string
	{
		$signed = self::canonicalHeaders($headers, self::hostOf($url));
		$lines  = '';
		foreach ($signed as $name => $value) {
			$lines .= $name . ':' . $value . "\n";
		}

		$path = (string)parse_url($url, PHP_URL_PATH);

		return strtoupper($method) . "\n"
			// Decode first, so a path that arrives encoded is not encoded twice.
			. self::encodePath(rawurldecode($path === '' ? '/' : $path)) . "\n"
			. self::canonicalQuery($url, $query) . "\n"
			. $lines . "\n"
			. implode(';', array_keys($signed)) . "\n"
			. $payloadHash;
	}

	/** URI-encode one value: RFC 3986 unreserved characters stay, a space is %20. */
	public static function encode(string $value): string
	{
		return rawurlencode($value);
	}

	/** URI-encode each segment of a path once; the slashes between segments stay. */
	public static function encodePath(string $path): string
	{
		return implode('/', array_map(rawurlencode(...), explode('/', $path)));
	}

	private function signature(string $canonicalRequest, \DateTimeImmutable $now): string
	{
		$stringToSign = self::ALGORITHM . "\n"
			. $now->format('Ymd\THis\Z') . "\n"
			. $this->scope($now) . "\n"
			. hash('sha256', $canonicalRequest);

		$key = hash_hmac('sha256', $now->format('Ymd'), 'AWS4' . $this->secretKey, true);
		$key = hash_hmac('sha256', $this->region, $key, true);
		$key = hash_hmac('sha256', $this->service, $key, true);
		$key = hash_hmac('sha256', 'aws4_request', $key, true);

		return hash_hmac('sha256', $stringToSign, $key);
	}

	private function scope(\DateTimeImmutable $now): string
	{
		return $now->format('Ymd') . '/' . $this->region . '/' . $this->service . '/aws4_request';
	}

	/**
	 * Lowercase names, trimmed values with inner runs of spaces collapsed, sorted by name.
	 *
	 * @param array<string,string> $headers
	 *
	 * @return array<string,string>
	 */
	private static function canonicalHeaders(array $headers, string $host): array
	{
		$canonical = ['host' => $host];
		foreach ($headers as $name => $value) {
			$canonical[strtolower(trim((string)$name))] = trim((string)preg_replace('/\s+/', ' ', (string)$value));
		}
		ksort($canonical, SORT_STRING);

		return $canonical;
	}

	/**
	 * The URL's own query plus $extra, each name and value encoded, sorted by encoded name then value.
	 *
	 * @param array<string,string> $extra
	 */
	private static function canonicalQuery(string $url, array $extra): string
	{
		$pairs = [];
		$own   = (string)parse_url($url, PHP_URL_QUERY);
		foreach ($own === '' ? [] : explode('&', $own) as $pair) {
			if ($pair === '') {
				continue;
			}
			[$name, $value] = explode('=', $pair, 2) + [1 => ''];
			$pairs[]        = [self::encode(rawurldecode($name)), self::encode(rawurldecode($value))];
		}
		foreach ($extra as $name => $value) {
			$pairs[] = [self::encode((string)$name), self::encode((string)$value)];
		}

		usort($pairs, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

		return implode('&', array_map(fn (array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
	}

	/** The Host header a client sends for this URL: the port only when it is not the scheme's default. */
	private static function hostOf(string $url): string
	{
		$parts = parse_url($url);
		$host  = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
		$port  = is_array($parts) ? ($parts['port'] ?? null) : null;
		$https = is_array($parts) && strtolower((string)($parts['scheme'] ?? 'https')) === 'https';

		return $port === null || $port === ($https ? 443 : 80) ? $host : $host . ':' . $port;
	}
}
