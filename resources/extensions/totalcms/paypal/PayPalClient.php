<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\PayPal;

use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * Talks to PayPal's REST API: JSON both ways, an access token asked for
 * with the app's client id and secret. No SDK.
 *
 * The secret goes in the Authorization header of the token request and
 * nowhere else; the token in the Authorization header of the requests after
 * it. An error from here names PayPal's own issue; it never quotes PayPal's
 * message, the secret or the token.
 */
final class PayPalClient
{
	public const SANDBOX = 'https://api-m.sandbox.paypal.com';
	public const LIVE    = 'https://api-m.paypal.com';

	private const TIMEOUT = 20;

	/** The access token, kept for the rest of this request. */
	private string $token = '';

	/**
	 * @param \Closure(): HttpClientInterface $http fetched when a request is made, not when the extension registers
	 */
	public function __construct(
		private readonly \Closure $http,
		private readonly string $clientId,
		private readonly string $secret,
		private readonly bool $live,
	) {
	}

	/**
	 * @param array<string,mixed> $body      an empty one is sent as `{}`
	 * @param string              $requestId PayPal answers a repeat of the same id with its first answer
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ProviderException
	 */
	public function post(string $path, array $body, string $requestId = ''): array
	{
		return $this->postJson($path, $body === [] ? '{}' : (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $requestId);
	}

	/**
	 * Post a body that is already JSON, byte for byte as given.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ProviderException
	 */
	public function postJson(string $path, string $json, string $requestId = ''): array
	{
		return $this->send('POST', $path, ['body' => $json], $requestId !== '' ? ['PayPal-Request-Id: ' . $requestId] : []);
	}

	/**
	 * @return array<string,mixed>
	 *
	 * @throws ProviderException
	 */
	public function get(string $path): array
	{
		return $this->send('GET', $path, [], []);
	}

	/**
	 * @param array<string,mixed> $options
	 * @param list<string>        $headers
	 *
	 * @return array<string,mixed>
	 */
	private function send(string $method, string $path, array $options, array $headers): array
	{
		$response = $this->request($method, $path, $options + [
			'headers' => ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json', ...$headers],
		]);

		$json = $response->json();
		if ($response->isSuccess() && $json !== null) {
			return $json;
		}

		// PayPal's error: a name for the kind, and often an issue that says exactly what.
		$name    = is_string($json['name'] ?? null) ? $json['name'] : '';
		$details = is_array($json['details'] ?? null) && is_array($json['details'][0] ?? null) ? $json['details'][0] : [];
		$issue   = is_string($details['issue'] ?? null) ? $details['issue'] : '';

		throw new ProviderException(
			sprintf('PayPal answered %d to %s %s%s.', $response->statusCode, $method, $path, $name === '' ? '' : " ({$name}" . ($issue === '' ? '' : ": {$issue}") . ')'),
			$issue !== '' ? $issue : ($name !== '' ? $name : 'network'),
		);
	}

	/** @throws ProviderException when PayPal will not give one: the client id or the secret is wrong */
	private function token(): string
	{
		if ($this->token !== '') {
			return $this->token;
		}

		$response = $this->request('POST', '/v1/oauth2/token', [
			'body'    => 'grant_type=client_credentials',
			'headers' => ['Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->secret), 'Content-Type: application/x-www-form-urlencoded'],
		]);

		$json  = $response->json();
		$token = is_string($json['access_token'] ?? null) ? $json['access_token'] : '';
		if (!$response->isSuccess() || $token === '') {
			$error = is_string($json['error'] ?? null) ? $json['error'] : '';

			throw new ProviderException(sprintf('PayPal answered %d to the request for an access token%s.', $response->statusCode, $error === '' ? '' : " ({$error})"), $error !== '' ? $error : 'network');
		}

		return $this->token = $token;
	}

	/** @param array<string,mixed> $options */
	private function request(string $method, string $path, array $options): HttpResponse
	{
		try {
			return ($this->http)()->request($method, ($this->live ? self::LIVE : self::SANDBOX) . $path, $options + ['timeout' => self::TIMEOUT, 'connect_timeout' => 10]);
		} catch (\RuntimeException) {
			// The transport's message can carry the request, headers and all.
			throw new ProviderException("PayPal could not be reached ({$method} {$path}).", 'network');
		}
	}
}
