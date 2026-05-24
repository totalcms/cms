<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

readonly class OAuthJwksAction
{
	public function __construct(
		private Config $config,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$publicPath = (string)($this->config->oauth['publicKeyPath'] ?? '');
		if ($publicPath === '' || !is_file($publicPath)) {
			return $this->renderer->json($response, ['keys' => []], 503);
		}

		$pem = (string)file_get_contents($publicPath);
		$res = openssl_pkey_get_public($pem);
		if ($res === false) {
			return $this->renderer->json($response, ['keys' => []], 503);
		}

		$details = openssl_pkey_get_details($res);
		if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'], $details['key'])) {
			return $this->renderer->json($response, ['keys' => []], 503);
		}

		$jwk = [
			'kty' => 'RSA',
			'use' => 'sig',
			'alg' => 'RS256',
			'kid' => substr(hash('sha256', (string)$details['key']), 0, 16),
			'n'   => $this->base64url((string)$details['rsa']['n']),
			'e'   => $this->base64url((string)$details['rsa']['e']),
		];

		return $this->renderer->json($response, ['keys' => [$jwk]]);
	}

	private function base64url(string $bin): string
	{
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}
}
