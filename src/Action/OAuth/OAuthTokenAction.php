<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;

readonly class OAuthTokenAction
{
	public function __construct(
		private AuthorizationServer $authServer,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$response = $this->authServer->respondToAccessTokenRequest($request, $response);
		} catch (OAuthServerException $e) {
			return $e->generateHttpResponse($response);
		}

		if ($response->getStatusCode() === 200) {
			$body = (string)$response->getBody();
			$response->getBody()->rewind();
			$payload = json_decode($body, true);

			if (is_array($payload) && isset($payload['scope'])) {
				$parsedBody = (array)($request->getParsedBody() ?? []);
				$clientId   = (string)($parsedBody['client_id'] ?? '');
				$grantType  = (string)($parsedBody['grant_type'] ?? '');
				$scopes     = $payload['scope'] !== '' ? explode(' ', (string)$payload['scope']) : [];

				if ($grantType === 'authorization_code') {
					$this->activityLogger->tokenIssued($clientId, '', $scopes);
				} elseif ($grantType === 'refresh_token') {
					// grant_id is not surfaced in the league response body; pass '' as
					// a known limitation — improving this would require hooking into
					// league internals. client_id + grant type is sufficient for the audit trail.
					$this->activityLogger->tokenRefreshed($clientId, '');
				}
			}
		}

		return $response;
	}
}
