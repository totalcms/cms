<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe;

use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Support\HttpClientInterface;

/**
 * Talks to Stripe's API: form-encoded requests, JSON answers, one pinned
 * API version. No SDK.
 *
 * The secret key goes in the Authorization header and nowhere else. An
 * error from here names Stripe's error type and code; it never quotes
 * Stripe's message, which can repeat what was sent, and never the key.
 */
final readonly class StripeClient
{
	public const BASE = 'https://api.stripe.com/v1';

	/** The API version every request is made with, so a change at Stripe cannot change what comes back. */
	public const VERSION = '2026-11-18.endive';

	private const TIMEOUT = 20;

	/**
	 * @param \Closure(): HttpClientInterface $http fetched when a request is made, not when the extension registers
	 */
	public function __construct(
		private \Closure $http,
		private string $secretKey,
	) {
	}

	/**
	 * @param array<string,mixed> $form           nested arrays become `a[b][c]=…`
	 * @param string              $idempotencyKey Stripe answers a repeat of the same key with the first answer
	 *
	 * @throws ProviderException
	 *
	 * @return array<string,mixed>
	 */
	public function post(string $path, array $form, string $idempotencyKey): array
	{
		return $this->send('POST', $path, [
			'body'    => http_build_query($this->plain($form)),
			'headers' => [...$this->headers(), 'Content-Type: application/x-www-form-urlencoded', 'Idempotency-Key: ' . $idempotencyKey],
		]);
	}

	/**
	 * @param array<string,mixed> $query
	 *
	 * @throws ProviderException
	 *
	 * @return array<string,mixed>
	 */
	public function get(string $path, array $query = []): array
	{
		return $this->send('GET', $path . ($query === [] ? '' : '?' . http_build_query($this->plain($query))), ['headers' => $this->headers()]);
	}

	/**
	 * @throws ProviderException
	 *
	 * @return array<string,mixed>
	 */
	public function delete(string $path): array
	{
		return $this->send('DELETE', $path, ['headers' => $this->headers()]);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function send(string $method, string $path, array $options): array
	{
		try {
			$response = ($this->http)()->request($method, self::BASE . $path, $options + ['timeout' => self::TIMEOUT, 'connect_timeout' => 10]);
		} catch (\RuntimeException) {
			// The transport's message can carry the request, headers and all.
			throw new ProviderException("Stripe could not be reached ({$method} {$path}).", 'network');
		}

		$json = $response->json();
		if ($response->isSuccess() && $json !== null) {
			return $json;
		}

		$error = is_array($json['error'] ?? null) ? $json['error'] : [];
		$type  = is_string($error['type'] ?? null) ? $error['type'] : '';
		$code  = is_string($error['code'] ?? null) ? $error['code'] : '';

		throw new ProviderException(
			sprintf('Stripe answered %d to %s %s%s.', $response->statusCode, $method, $path, $type === '' ? '' : " ({$type}" . ($code === '' ? '' : ": {$code}") . ')'),
			$code !== '' ? $code : ($type !== '' ? $type : 'network'),
		);
	}

	/** @return list<string> */
	private function headers(): array
	{
		return ['Authorization: Bearer ' . $this->secretKey, 'Stripe-Version: ' . self::VERSION];
	}

	/**
	 * Stripe reads `true` and `false`, where PHP would write 1 and 0.
	 *
	 * @param array<array-key,mixed> $values
	 *
	 * @return array<array-key,mixed>
	 */
	private function plain(array $values): array
	{
		foreach ($values as $key => $value) {
			$values[$key] = match (true) {
				is_bool($value)  => $value ? 'true' : 'false',
				is_array($value) => $this->plain($value),
				default          => $value,
			};
		}

		return $values;
	}
}
