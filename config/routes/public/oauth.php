<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\OAuth\OAuthApproveAction;
use TotalCMS\Action\OAuth\OAuthAuthorizeAction;
use TotalCMS\Action\OAuth\OAuthDiscoveryAction;
use TotalCMS\Action\OAuth\OAuthJwksAction;
use TotalCMS\Action\OAuth\OAuthProtectedResourceAction;
use TotalCMS\Action\OAuth\OAuthRegisterAction;
use TotalCMS\Action\OAuth\OAuthRevokeAction;
use TotalCMS\Action\OAuth\OAuthTokenAction;
use TotalCMS\Middleware\License\OAuthEditionMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;
use TotalCMS\Middleware\Security\CSRFProtectionMiddleware;
use TotalCMS\Middleware\Security\OAuthEnabledMiddleware;
use TotalCMS\Middleware\Security\OAuthTokenRateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// OAuth 2.1 authorization server. Phase 4 ships authorization-code +
	// refresh-token grants; PKCE required on every flow. /oauth/revoke
	// and /oauth/register land in Chunk B.
	//
	// EVERY route below — well-knowns included — carries OAuthEnabledMiddleware
	// as its outermost wrapper: oauth.enabled=false must kill discovery AND
	// issuance together (hidden metadata with a live /oauth/token would let
	// existing refresh tokens keep minting access tokens forever). Sites turn
	// OAuth off to run public-only MCP — Claude's consumer apps demand login
	// whenever OAuth is discoverable, and their consent screen needs an
	// operator account no site visitor has.

	// RFC 8414 — discovery metadata. Clients (Claude, Cursor, ActivePieces)
	// hit this first to find endpoints + supported scopes + grant types.
	$app->get('/.well-known/oauth-authorization-server', OAuthDiscoveryAction::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.discovery');

	// RFC 7517 — JWK Set publishing the access-token signing public key.
	// Resource servers verifying JWTs fetch this to get the RSA public key
	// for signature validation.
	$app->get('/.well-known/jwks.json', OAuthJwksAction::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.jwks');

	// RFC 9728 — protected resource metadata. The MCP endpoint's 401
	// WWW-Authenticate header points clients here (resource_metadata=...) so
	// they can discover the authorization server protecting the MCP resource.
	$app->get('/.well-known/oauth-protected-resource', OAuthProtectedResourceAction::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.protected-resource');

	// Authorization endpoint. GET renders the consent screen for a logged-in
	// admin; POST captures the approve/deny decision and completes the flow
	// (issuing the auth code and redirecting back to the client). Admin
	// session required — anonymous requests redirect to admin login.
	//
	// AuthMiddleware deliberately NOT applied — the action does its own
	// session check + redirect-to-login with ?next= preservation. Wrapping
	// with AuthMiddleware would also leak client_id validity to unauth'd
	// callers (valid client -> login redirect, invalid client -> OAuth
	// error page; attackers could distinguish them).
	//
	// Slim's ->add() wraps inside-out: the LAST add() runs FIRST. On POST,
	// NoCache outer / CSRF inner means CSRF validates before the action
	// runs, and the no-cache header lands on the final response.
	// Edition-gated routes below. OAuthEditionMiddleware is applied as the
	// OUTERMOST add() on each — runs FIRST per Slim's inside-out wrapping —
	// so non-Pro instances get a clean 403 access-denied before any OAuth
	// logic executes. The .well-known/* endpoints above stay accessible but
	// 404 inline on non-Pro (handled inside their actions) so external
	// clients can detect the unsupported server through standard discovery
	// failure rather than getting a license error.
	$app->get('/oauth/authorize', OAuthAuthorizeAction::class)
		->add(NoCacheMiddleware::class)
		->add(OAuthEditionMiddleware::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.authorize');
	$app->post('/oauth/authorize', OAuthApproveAction::class)
		->add(CSRFProtectionMiddleware::class)
		->add(NoCacheMiddleware::class)
		->add(OAuthEditionMiddleware::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.approve');

	// Token endpoint. Exchanges authorization codes (with PKCE verifier)
	// for access + refresh tokens, and refreshes existing tokens via the
	// refresh_token grant. Rate-limited per IP to defeat brute-force code
	// exchange and runaway refresh loops. NoCache because the response
	// payload contains short-lived access tokens.
	$app->post('/oauth/token', OAuthTokenAction::class)
		->add(OAuthTokenRateLimitMiddleware::class)
		->add(NoCacheMiddleware::class)
		->add(OAuthEditionMiddleware::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.token');

	// RFC 7591 — dynamic client registration. Lets MCP clients (Claude,
	// Cursor) self-register OAuth credentials without admin intervention.
	// OFF by default ($config->oauth['dynamicRegistration'] = false) because
	// it creates persistent server state from an unauthenticated request;
	// operators opt in. Rate-limited tightly even when enabled. NoCache
	// because the response carries a one-time client_secret.
	$app->post('/oauth/register', OAuthRegisterAction::class)
		->add(OAuthTokenRateLimitMiddleware::class)
		->add(NoCacheMiddleware::class)
		->add(OAuthEditionMiddleware::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.register');

	// RFC 7009 — token revocation. Clients can call this to revoke their
	// own tokens (access or refresh). Always returns 200 to avoid leaking
	// token-existence info; only 400 on missing param or 401 on client
	// auth failure. NoCache because outcomes are session/auth-dependent.
	$app->post('/oauth/revoke', OAuthRevokeAction::class)
		->add(NoCacheMiddleware::class)
		->add(OAuthEditionMiddleware::class)
		->add(OAuthEnabledMiddleware::class)
		->setName('oauth.revoke');
};
