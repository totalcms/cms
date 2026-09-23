<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Support\HttpClientInterface;

/**
 * The HTTP leg of a sync: authenticate with the API key, post a payload or
 * fetch the remote's export, and turn a refusal into one readable error.
 *
 * Authentication uses the X-API-Key header, not `Authorization: Bearer`:
 * OAuthBearerMiddleware (the outer layer on the /api group) intercepts any
 * Bearer token and tries to validate it as a JWT, so a plain API key would
 * 401 before the API-key middleware ever ran. X-API-Key is invisible to it.
 */
readonly class SyncTransport
{
	public const EXPORT_ROUTE        = '/api/sync/export';
	public const LEGACY_EXPORT_ROUTE = '/api/export/jumpstart?mode=sync';

	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	/**
	 * POST one payload and decode the remote's answer.
	 *
	 * @throws \RuntimeException On a transport error or a 4xx/5xx answer
	 *
	 * @return array<string,mixed>
	 */
	public function post(string $url, string $key, string $endpoint, JumpStartData $payload): array
	{
		$response = $this->httpClient->request('POST', $this->base($url) . $endpoint, [
			'headers' => array_merge($this->headers($key), ['Content-Type: application/json']),
			'body'    => $payload->toJson(),
			'timeout' => 60,
		]);

		if ($response->statusCode >= 400) {
			throw new \RuntimeException(sprintf('Push failed (HTTP %d): %s', $response->statusCode, self::remoteError($response->body)));
		}

		$decoded = json_decode($response->body, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * GET the remote's sync export.
	 *
	 * `/api/sync/export` is the canonical source: it lives under /sync so the
	 * "Sync Manager" API-key endpoint option covers both directions with one
	 * path grant. Any 4xx falls back to the legacy `/api/export/jumpstart?mode=sync`,
	 * which keeps two real cases working — a remote on an older release
	 * without the route (404), and a key created before the Sync Manager
	 * option existed whose grant covers /export but not /sync (403).
	 *
	 * @throws \RuntimeException On a transport error, a 4xx/5xx answer, or an unreadable body
	 *
	 * @return array<string,mixed>
	 */
	public function fetchExport(string $url, string $key): array
	{
		$options  = ['headers' => $this->headers($key), 'timeout' => 60];
		$response = $this->httpClient->request('GET', $this->base($url) . self::EXPORT_ROUTE, $options);

		if ($response->statusCode >= 400 && $response->statusCode < 500) {
			$response = $this->httpClient->request('GET', $this->base($url) . self::LEGACY_EXPORT_ROUTE, $options);
		}

		if ($response->statusCode >= 400) {
			throw new \RuntimeException(sprintf('Pull failed (HTTP %d): %s', $response->statusCode, self::remoteError($response->body)));
		}

		$payload = json_decode($response->body, true);
		if (!is_array($payload)) {
			throw new \RuntimeException('Pull failed: invalid response from remote.');
		}

		return $payload;
	}

	/**
	 * Whether a remote import actually succeeded, and what it reported.
	 *
	 * A 2xx only means the payload was accepted for import: the receiving
	 * importer collects per-item failures and still answers 200, so the
	 * transport succeeding says nothing about whether anything was written.
	 * Reporting a clean push over such an answer once hid errors the remote
	 * had diagnosed precisely.
	 *
	 * @param mixed $success The remote's `success` flag (absent counts as success)
	 * @param mixed $errors  The remote's error bag, in whatever shape it sent
	 *
	 * @return array{0: bool, 1: list<string>}
	 */
	public static function verdict(mixed $success, mixed $errors): array
	{
		$list = [];
		if (is_array($errors)) {
			foreach ($errors as $entry) {
				if (is_scalar($entry) || $entry instanceof \Stringable) {
					$list[] = (string)$entry;
				}
			}
		}

		return [$success !== false && $list === [], $list];
	}

	/** @return list<string> */
	private function headers(string $key): array
	{
		return ['X-API-Key: ' . $key, 'Accept: application/json'];
	}

	/** A trailing slash on the configured URL must not produce `//api`. */
	private function base(string $url): string
	{
		return rtrim($url, '/');
	}

	/**
	 * A usable error message from a remote response body. T3's error
	 * responses look like `{"error": {"message": "...", "code": "..."}}`;
	 * stringifying that object gave the literal "Array". Handle the nested
	 * shape, fall back to flat error/message strings, then to a trimmed
	 * slice of the raw body.
	 */
	public static function remoteError(string $body): string
	{
		$decoded = json_decode($body, true);

		if (is_array($decoded)) {
			$error = $decoded['error'] ?? null;

			if (is_array($error) && is_string($error['message'] ?? null)) {
				return $error['message'];
			}
			if (is_string($error)) {
				return $error;
			}
			if (is_string($decoded['message'] ?? null)) {
				return $decoded['message'];
			}
		}

		$trimmed = trim($body);
		if ($trimmed === '') {
			return '(empty response body)';
		}

		return strlen($trimmed) > 500 ? substr($trimmed, 0, 500) . '…' : $trimmed;
	}
}
