<?php

namespace TotalCMS\Support;

/**
 * cURL-based implementation of HttpClientInterface.
 *
 * This preserves the existing curl behavior while allowing
 * it to be swapped for Guzzle later.
 */
class CurlHttpClient implements HttpClientInterface
{
	/**
	 * @param array<string,mixed> $options
	 */
	public function request(string $method, string $url, array $options = []): HttpResponse
	{
		$ch = curl_init($url);
		if ($ch === false) {
			throw new \RuntimeException('Failed to initialize cURL');
		}

		$curlOptions = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
		];

		// Timeout
		if (isset($options['timeout'])) {
			$curlOptions[CURLOPT_TIMEOUT] = (int)$options['timeout'];
		}
		if (isset($options['connect_timeout'])) {
			$curlOptions[CURLOPT_CONNECTTIMEOUT] = (int)$options['connect_timeout'];
		}

		// SSL verification
		$verifySSL = $options['verify_ssl'] ?? true;
		$curlOptions[CURLOPT_SSL_VERIFYPEER] = $verifySSL;
		$curlOptions[CURLOPT_SSL_VERIFYHOST] = $verifySSL ? 2 : 0;

		// Redirects
		$followRedirects = $options['follow_redirects'] ?? false;
		if ($followRedirects !== false) {
			$curlOptions[CURLOPT_FOLLOWLOCATION] = true;
			$curlOptions[CURLOPT_MAXREDIRS] = is_int($followRedirects) ? $followRedirects : 5;
		}

		// User agent
		if (isset($options['user_agent'])) {
			$curlOptions[CURLOPT_USERAGENT] = (string)$options['user_agent'];
		}

		// Headers
		if (isset($options['headers']) && is_array($options['headers'])) {
			$curlOptions[CURLOPT_HTTPHEADER] = $options['headers'];
		}

		// Request body
		if (isset($options['body'])) {
			$curlOptions[CURLOPT_POSTFIELDS] = (string)$options['body'];
		}

		// POST shorthand
		if ($method === 'POST' && !isset($curlOptions[CURLOPT_POSTFIELDS])) {
			$curlOptions[CURLOPT_POST] = true;
		}

		// Sink (write response to file)
		$sinkFp = null;
		if (isset($options['sink'])) {
			$sink = $options['sink'];
			if (is_string($sink)) {
				$sinkFp = fopen($sink, 'w');
				if ($sinkFp === false) {
					throw new \RuntimeException('Unable to open sink file: ' . $sink);
				}
			} elseif (is_resource($sink)) {
				$sinkFp = $sink;
			}
			if ($sinkFp !== null) {
				$curlOptions[CURLOPT_FILE] = $sinkFp;
				unset($curlOptions[CURLOPT_RETURNTRANSFER]);
			}
		}

		// Max download size enforcement via progress callback
		$maxBytes = (int)($options['max_bytes'] ?? 0);
		if ($maxBytes > 0) {
			$curlOptions[CURLOPT_NOPROGRESS] = false;
			$curlOptions[CURLOPT_PROGRESSFUNCTION] = fn (
				\CurlHandle $resource,
				int $downloadSize,
				int $downloaded,
				int $uploadSize,
				int $uploaded
			): int => ($downloadSize > $maxBytes || $downloaded > $maxBytes) ? 1 : 0;
		}

		curl_setopt_array($ch, $curlOptions);

		$result    = curl_exec($ch);
		$httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);

		if ($sinkFp !== null && is_string($options['sink'] ?? null)) {
			fclose($sinkFp);
		}

		if ($result === false || $curlError !== '') {
			if ($maxBytes > 0 && str_contains($curlError, 'aborted by callback')) {
				throw new \RuntimeException('Download exceeds maximum size limit');
			}
			throw new \RuntimeException('HTTP request failed: ' . ($curlError ?: 'Unknown cURL error'));
		}

		$body = is_string($result) ? $result : '';

		return new HttpResponse($httpCode, $body);
	}
}
