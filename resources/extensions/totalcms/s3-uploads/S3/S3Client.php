<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\S3;

use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * The few S3 calls this extension needs, signed with SigV4 and sent through
 * the site's HTTP client. Works with any provider that speaks the S3 API.
 *
 * Only the endpoint a super admin configured is ever requested. Nothing here
 * logs: a presigned URL is a credential.
 */
final class S3Client
{
	private const CONNECT_TIMEOUT = 10;
	private const TIMEOUT         = 30;

	private readonly SigV4Signer $signer;

	/** @var \Closure(): \DateTimeImmutable */
	private readonly \Closure $clock;

	/** @param (\Closure(): \DateTimeImmutable)|null $clock defaults to now, in UTC */
	public function __construct(
		private readonly S3Settings $settings,
		private readonly HttpClientInterface $http,
		?\Closure $clock = null,
	) {
		$this->signer = new SigV4Signer($settings->accessKey, $settings->secretKey, $settings->region);
		$this->clock  = $clock ?? fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}

	/**
	 * @return array{size:int, mime:string, etag:string, lastModified:string}|null null when the key is not in the bucket
	 *
	 * @throws S3Exception
	 */
	public function headObject(string $key): ?array
	{
		$response = $this->send('HEAD', $this->objectUrl($key));
		if ($response->statusCode === 404) {
			return null;
		}
		if ($response->statusCode !== 200) {
			throw $this->failure($response, 'HEAD');
		}

		return [
			'size'         => (int)($response->headers['content-length'] ?? 0),
			'mime'         => (string)($response->headers['content-type'] ?? ''),
			'etag'         => self::etag((string)($response->headers['etag'] ?? '')),
			'lastModified' => (string)($response->headers['last-modified'] ?? ''),
		];
	}

	/**
	 * Copy one key to another inside the bucket.
	 *
	 * @throws S3Exception
	 */
	public function copyObject(string $from, string $to): void
	{
		$response = $this->send('PUT', $this->objectUrl($to), [
			'x-amz-copy-source'        => '/' . $this->settings->bucket . '/' . SigV4Signer::encodePath($from),
			'x-amz-metadata-directive' => 'COPY',
		]);

		// CopyObject can answer 200 and then report the failure in the body.
		if ($response->statusCode !== 200 || str_contains($response->body, '<Error>')) {
			throw $this->failure($response, 'PUT');
		}
	}

	/**
	 * Delete a key. A key that is already gone counts as deleted.
	 *
	 * @throws S3Exception
	 */
	public function deleteObject(string $key): void
	{
		$response = $this->send('DELETE', $this->objectUrl($key));
		if (!in_array($response->statusCode, [200, 204, 404], true)) {
			throw $this->failure($response, 'DELETE');
		}
	}

	/**
	 * Every object under $prefix, one page of $maxKeys at a time.
	 *
	 * @return \Generator<int, ListedObject>
	 *
	 * @throws S3Exception
	 */
	public function listObjects(string $prefix, int $maxKeys = 1000): \Generator
	{
		$token = null;

		do {
			$query = ['list-type' => '2', 'max-keys' => (string)$maxKeys, 'prefix' => $prefix];
			if ($token !== null) {
				$query['continuation-token'] = $token;
			}
			ksort($query, SORT_STRING);

			$response = $this->send('GET', $this->bucketUrl() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
			$xml      = $response->statusCode === 200 ? self::xml($response->body) : null;
			if ($xml === null) {
				throw $this->failure($response, 'GET');
			}

			foreach ($xml->Contents as $item) {
				yield new ListedObject(
					(string)$item->Key,
					(int)$item->Size,
					(int)strtotime((string)$item->LastModified),
					self::etag((string)$item->ETag),
				);
			}

			$token = (string)$xml->IsTruncated === 'true' ? (string)$xml->NextContinuationToken : null;
		} while ($token !== null && $token !== '');
	}

	/**
	 * A URL the browser can PUT exactly one file to: this key, this type, this size.
	 *
	 * The PUT must send Content-Type with the same value. The browser sets
	 * Content-Length itself, and the bucket refuses a body of another size.
	 */
	public function presignPut(string $key, string $mime, int $size, int $expires): string
	{
		return $this->signer->presign(
			'PUT',
			$this->objectUrl($key),
			['content-type' => $mime, 'content-length' => (string)$size],
			$expires,
			($this->clock)(),
		);
	}

	/**
	 * A URL that reads the key for $expires seconds.
	 *
	 * @param string|null $attachmentName when given, the browser downloads the file under this name instead of showing it
	 */
	public function presignGet(string $key, int $expires, ?string $attachmentName = null): string
	{
		$query = $attachmentName === null ? [] : ['response-content-disposition' => self::contentDisposition($attachmentName)];

		return $this->signer->presign('GET', $this->objectUrl($key), [], $expires, ($this->clock)(), $query);
	}

	/** The key's URL at the bucket. Readable by anyone only when the bucket is public. */
	public function objectUrl(string $key): string
	{
		return $this->settings->bucketBaseUrl() . '/' . SigV4Signer::encodePath(ltrim($key, '/'));
	}

	/**
	 * The hash inside an ETag. HTTP defines the header as a quoted string, so
	 * the bucket sends `"3d0f6c…"`; the quotes are wrapping, not content.
	 */
	private static function etag(string $header): string
	{
		return trim($header, '"');
	}

	/** RFC 6266: an ASCII name every browser reads, and the real name for those that read filename*. */
	public static function contentDisposition(string $name): string
	{
		$ascii = $name;
		if (class_exists(\Transliterator::class)) {
			$converted = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($name);
			$ascii     = is_string($converted) ? $converted : $name;
		}
		$ascii = (string)preg_replace('/[^\x20-\x7E]/', '_', $ascii);
		$ascii = trim((string)preg_replace('/\s+/', ' ', str_replace(['"', '\\'], '', $ascii)));

		return 'attachment; filename="' . ($ascii === '' ? 'download' : $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($name);
	}

	/** The bucket itself, for listing: the host root for virtual-hosted style, the bucket path for path style. */
	private function bucketUrl(): string
	{
		return $this->settings->usePathStyle() ? $this->settings->bucketBaseUrl() : $this->settings->bucketBaseUrl() . '/';
	}

	/**
	 * @param array<string,string> $headers
	 *
	 * @throws S3Exception when no answer comes back
	 */
	private function send(string $method, string $url, array $headers = []): HttpResponse
	{
		$signed = $this->signer->signHeaders($method, $url, $headers, SigV4Signer::EMPTY_SHA256, ($this->clock)());

		try {
			$response = $this->http->request($method, $url, [
				'headers'          => $signed,
				'timeout'          => self::TIMEOUT,
				'connect_timeout'  => self::CONNECT_TIMEOUT,
				'follow_redirects' => false,
			]);
		} catch (\RuntimeException) {
			throw new S3Exception('NetworkError', 0, 'The bucket could not be reached.');
		}

		if ($response->statusCode === 0) {
			throw new S3Exception('NetworkError', 0, 'The bucket could not be reached.');
		}

		return $response;
	}

	private function failure(HttpResponse $response, string $method): S3Exception
	{
		$xml     = self::xml($response->body);
		$code    = $xml !== null ? (string)$xml->Code : '';
		$message = $xml !== null ? (string)$xml->Message : '';
		$region  = (string)($response->headers['x-amz-bucket-region'] ?? ($xml !== null ? (string)$xml->Region : ''));

		if ($code === '') {
			// HEAD answers carry no body, so the status has to name the problem.
			$code = match ($response->statusCode) {
				301, 307 => 'PermanentRedirect',
				400      => 'BadRequest',
				403      => 'AccessDenied',
				404      => 'NotFound',
				default  => 'Http' . $response->statusCode,
			};
		}

		return new S3Exception($code, $response->statusCode, $message !== '' ? $message : "{$method} answered HTTP {$response->statusCode}.", $region);
	}

	private static function xml(string $body): ?\SimpleXMLElement
	{
		if (trim($body) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$xml      = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $xml instanceof \SimpleXMLElement ? $xml : null;
	}
}
