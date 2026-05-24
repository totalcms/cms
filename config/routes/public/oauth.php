<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\OAuth\OAuthApproveAction;
use TotalCMS\Action\OAuth\OAuthAuthorizeAction;
use TotalCMS\Action\OAuth\OAuthDiscoveryAction;
use TotalCMS\Action\OAuth\OAuthJwksAction;
use TotalCMS\Action\OAuth\OAuthTokenAction;
use TotalCMS\Middleware\Security\OAuthTokenRateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// OAuth 2.1 authorization server. Phase 4 ships authorization-code +
	// refresh-token grants; PKCE required on every flow. /oauth/revoke
	// and /oauth/register land in Chunk B.

	// RFC 8414 — discovery metadata. Clients (Claude, Cursor, ActivePieces)
	// hit this first to find endpoints + supported scopes + grant types.
	$app->get('/.well-known/oauth-authorization-server', OAuthDiscoveryAction::class)
		->setName('oauth.discovery');

	// RFC 7517 — JWK Set publishing the access-token signing public key.
	// Resource servers verifying JWTs fetch this to get the RSA public key
	// for signature validation.
	$app->get('/.well-known/jwks.json', OAuthJwksAction::class)
		->setName('oauth.jwks');

	// Authorization endpoint. GET renders the consent screen for a logged-in
	// admin; POST captures the approve/deny decision and completes the flow
	// (issuing the auth code and redirecting back to the client). Admin
	// session required — anonymous requests redirect to admin login.
	$app->get('/oauth/authorize', OAuthAuthorizeAction::class)
		->setName('oauth.authorize');
	$app->post('/oauth/authorize', OAuthApproveAction::class)
		->setName('oauth.approve');

	// Token endpoint. Exchanges authorization codes (with PKCE verifier)
	// for access + refresh tokens, and refreshes existing tokens via the
	// refresh_token grant. Rate-limited per IP to defeat brute-force code
	// exchange and runaway refresh loops.
	$app->post('/oauth/token', OAuthTokenAction::class)
		->add(OAuthTokenRateLimitMiddleware::class)
		->setName('oauth.token');
};
