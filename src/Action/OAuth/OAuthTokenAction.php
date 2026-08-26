<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthClientPruner;

readonly class OAuthTokenAction
{
	public function __construct(
		private AuthorizationServer $authServer,
		private OAuthActivityLogger $activityLogger,
		private OAuthClientPruner $clientPruner,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$response = $this->authServer->respondToAccessTokenRequest($request, $response);
		} catch (OAuthServerException $e) {
			// Record the rejection. Without this the audit trail simply stops
			// after consent.granted and the reason lives only in the web
			// server's access log, which the operator usually cannot reach.
			$parsedBody   = (array)($request->getParsedBody() ?? []);
			$serverParams = $request->getServerParams();
			$this->activityLogger->tokenFailed(
				(string)($parsedBody['client_id'] ?? ''),
				(string)($parsedBody['grant_type'] ?? ''),
				$e->getErrorType(),
				$e->getHint(),
				(string)($serverParams['REMOTE_ADDR'] ?? ''),
			);

			return $e->generateHttpResponse($response);
		}

		if ($response->getStatusCode() === 200) {
			$body = (string)$response->getBody();
			$response->getBody()->rewind();
			$payload = json_decode($body, true);

			// The guard used to be `isset($payload['scope'])`, which league's
			// BearerTokenResponse never sets — its body is token_type, expires_in,
			// access_token and optionally refresh_token, nothing else. So neither
			// tokenIssued nor tokenRefreshed had ever written a line on any
			// install, and an OAuth activity log that documents itself as "the
			// authoritative record" silently omitted every issuance. Reading a
			// support case's log as "no token was issued" is exactly the wrong
			// conclusion that invited.
			if (is_array($payload) && isset($payload['access_token'])) {
				$parsedBody = (array)($request->getParsedBody() ?? []);
				$clientId   = (string)($parsedBody['client_id'] ?? '');
				$grantType  = (string)($parsedBody['grant_type'] ?? '');
				$scopes     = $this->scopesFromJwt((string)$payload['access_token']);

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

		// Active connectors refresh hourly, so this touchpoint gives every
		// OAuth-using site a daily sweep of expired grants and stale
		// self-registered clients. Throttled + failure-proof inside.
		$this->clientPruner->maybeRunDaily();

		return $response;
	}

	/**
	 * Granted scopes, read from the issued access token's `scopes` claim.
	 *
	 * The response body carries no scope field, but the access token is a JWS
	 * whose payload league builds with `->withClaim('scopes', …)`. Decoding the
	 * payload segment is read-only and needs no key: this is for the audit line,
	 * not for a trust decision, and the token was just minted by this process.
	 * Returns an empty list rather than throwing — a missing audit detail must
	 * never break a successful token exchange.
	 *
	 * @return list<string>
	 */
	private function scopesFromJwt(string $jwt): array
	{
		$segments = explode('.', $jwt);
		if (count($segments) !== 3) {
			return [];
		}

		$payload = base64_decode(strtr($segments[1], '-_', '+/'), true);
		if ($payload === false) {
			return [];
		}

		$claims = json_decode($payload, true);
		if (!is_array($claims) || !is_array($claims['scopes'] ?? null)) {
			return [];
		}

		return array_values(array_map(
			static fn (mixed $scope): string => is_string($scope) ? $scope : (string)json_encode($scope),
			$claims['scopes'],
		));
	}
}
