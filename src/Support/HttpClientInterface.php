<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Simple HTTP client interface for abstracting HTTP requests.
 *
 * Allows swapping curl for Guzzle (or any other HTTP client)
 * and enables mocking in tests.
 */
interface HttpClientInterface
{
	/**
	 * Send an HTTP request and return the response.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, etc.)
	 * @param string $url    Full URL to request
	 * @param array<string,mixed> $options Request options:
	 *   - 'headers'         => array<string,string> HTTP headers
	 *   - 'body'            => string Request body
	 *   - 'timeout'         => int Timeout in seconds
	 *   - 'connect_timeout' => int Connection timeout in seconds
	 *   - 'follow_redirects'=> bool|int Whether/how many redirects to follow
	 *   - 'verify_ssl'      => bool Whether to verify SSL certificates
	 *   - 'user_agent'      => string User-Agent header
	 *   - 'sink'            => string|resource File path or stream to write response body to
	 *   - 'max_bytes'       => int Maximum download size in bytes (0 = unlimited)
	 *
	 * @throws \RuntimeException on connection/transport errors
	 *
	 * @return HttpResponse
	 */
	public function request(string $method, string $url, array $options = []): HttpResponse;
}
