<?php

namespace TotalCMS\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Guzzle-based implementation of HttpClientInterface.
 */
class GuzzleHttpClient implements HttpClientInterface
{
	private Client $client;

	public function __construct(?Client $client = null)
	{
		$this->client = $client ?? new Client();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function request(string $method, string $url, array $options = []): HttpResponse
	{
		$guzzleOptions = [
			RequestOptions::HTTP_ERRORS => false,
		];

		// Timeout
		if (isset($options['timeout'])) {
			$guzzleOptions[RequestOptions::TIMEOUT] = (int)$options['timeout'];
		}
		if (isset($options['connect_timeout'])) {
			$guzzleOptions[RequestOptions::CONNECT_TIMEOUT] = (int)$options['connect_timeout'];
		}

		// SSL verification
		$guzzleOptions[RequestOptions::VERIFY] = $options['verify_ssl'] ?? true;

		// Redirects
		$followRedirects = $options['follow_redirects'] ?? false;
		if ($followRedirects === false) {
			$guzzleOptions[RequestOptions::ALLOW_REDIRECTS] = false;
		} elseif (is_int($followRedirects)) {
			$guzzleOptions[RequestOptions::ALLOW_REDIRECTS] = ['max' => $followRedirects];
		}
		// true uses Guzzle's default (5 redirects)

		// Headers
		$headers = [];
		if (isset($options['user_agent'])) {
			$headers['User-Agent'] = (string)$options['user_agent'];
		}
		// Merge raw header strings (e.g., "Content-Type: application/json")
		if (isset($options['headers']) && is_array($options['headers'])) {
			foreach ($options['headers'] as $header) {
				$parts = explode(':', (string)$header, 2);
				if (count($parts) === 2) {
					$headers[trim($parts[0])] = trim($parts[1]);
				}
			}
		}
		if ($headers !== []) {
			$guzzleOptions[RequestOptions::HEADERS] = $headers;
		}

		// Request body
		if (isset($options['body'])) {
			$guzzleOptions[RequestOptions::BODY] = (string)$options['body'];
		}

		// Sink (write response to file)
		if (isset($options['sink'])) {
			$guzzleOptions[RequestOptions::SINK] = $options['sink'];
		}

		// Max download size enforcement via progress callback
		$maxBytes = (int)($options['max_bytes'] ?? 0);
		if ($maxBytes > 0) {
			$guzzleOptions[RequestOptions::PROGRESS] = function (int $downloadTotal, int $downloadedBytes) use ($maxBytes): void {
				if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
					throw new \RuntimeException('Download exceeds maximum size limit');
				}
			};
		}

		try {
			$response = $this->client->request($method, $url, $guzzleOptions);
		} catch (\RuntimeException $e) {
			// Re-throw RuntimeExceptions (including our max size one) as-is
			throw $e;
		} catch (GuzzleException $e) {
			throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
		}

		return new HttpResponse(
			$response->getStatusCode(),
			(string)$response->getBody(),
		);
	}
}
