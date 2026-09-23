<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\TokenRevoker;
use TotalCMS\Renderer\JsonRenderer;

/**
 * RFC 7009 token revocation endpoint:
 *   - `token` (required) + `token_type_hint` (optional)
 *   - 400 if `token` is missing; 401 if client authentication fails
 *   - otherwise always 200, whether or not the token existed (§2.2: never
 *     leak token existence). {@see TokenRevoker} does the type detection.
 *
 * Client authentication is `client_id` + `client_secret` in the POST body
 * (§2.1). Basic auth is also acceptable per RFC but v1 only supports body
 * params.
 */
readonly class OAuthRevokeAction
{
	public function __construct(
		private OAuthClientRepository $clients,
		private TokenRevoker $revoker,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = (array)($request->getParsedBody() ?? []);
		if ($body === []) {
			parse_str((string)$request->getBody(), $body);
		}

		$token = (string)($body['token'] ?? '');
		if ($token === '') {
			return $this->renderer->json($response, [
				'error'             => 'invalid_request',
				'error_description' => 'token parameter is required',
			], 400);
		}

		$client = $this->clients->find((string)($body['client_id'] ?? ''));
		if (!$client instanceof OAuthClientData || !password_verify((string)($body['client_secret'] ?? ''), $client->secretHash)) {
			return $this->renderer->json($response, [
				'error'             => 'invalid_client',
				'error_description' => 'client authentication failed',
			], 401);
		}

		$this->revoker->revoke($token, (string)($body['token_type_hint'] ?? ''), $client);

		return $response->withStatus(200);
	}
}
