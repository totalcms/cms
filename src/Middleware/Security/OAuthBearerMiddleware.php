<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Service\OAuthServerFactory;

/**
 * Validates Authorization: Bearer <jwt> headers via league's ResourceServer
 * and sets oauth_* request attributes (jti, user_id, scopes, client_id) for
 * downstream consumers (McpAuth, OAuthScopeEvaluator).
 *
 * League's BearerTokenValidator sets these attributes on the validated request:
 *   - oauth_access_token_id  — the JTI (used for revocation checks)
 *   - oauth_client_id        — the client identifier (aud[0])
 *   - oauth_user_id          — the user identifier (sub claim)
 *   - oauth_scopes           — array of scope strings
 *
 * Requests WITHOUT a Bearer header pass through unchanged — subsequent
 * middleware in the chain (ApiKeyAuthenticator, session auth) handle those.
 * The presence of the Bearer header is the sole trigger.
 *
 * On Bearer failure (invalid signature, expired, revoked jti), returns
 * RFC 6750-compliant 401 with WWW-Authenticate: Bearer realm="T3",
 * error="invalid_token" and the exception's JSON error body.
 */
readonly class OAuthBearerMiddleware implements MiddlewareInterface
{
	public function __construct(
		private OAuthServerFactory $serverFactory,
		private OAuthRevocationList $revocationList,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$auth = $request->getHeaderLine('Authorization');
		if ($auth === '' || !str_starts_with(strtolower($auth), 'bearer ')) {
			return $handler->handle($request);
		}

		// Build the resource server lazily — only when a Bearer header is
		// actually present. If OAuth keys haven't been generated on this
		// install (fresh deploy, no `tcms oauth:setup` yet), CryptKey throws
		// "Invalid key supplied". We can't validate the token without keys,
		// so the only correct response is 401 — but we must NOT 500 the
		// request when there's no Bearer header at all (the normal case).
		try {
			$resourceServer = $this->serverFactory->buildResourceServer();
		} catch (\Throwable) {
			return (new Response(401))
				->withHeader('WWW-Authenticate', 'Bearer realm="T3", error="invalid_token"');
		}

		try {
			$validated = $resourceServer->validateAuthenticatedRequest($request);
		} catch (OAuthServerException $e) {
			$response = (new Response(401))
				->withHeader('WWW-Authenticate', 'Bearer realm="T3", error="invalid_token"');

			return $e->generateHttpResponse($response);
		}

		$jti = (string)$validated->getAttribute('oauth_access_token_id', '');
		if ($jti !== '' && $this->revocationList->isRevoked($jti)) {
			return (new Response(401))
				->withHeader('Content-Type', 'application/json')
				->withHeader(
					'WWW-Authenticate',
					'Bearer realm="T3", error="invalid_token", error_description="token revoked"',
				)
				->withBody(Stream::create((string)(json_encode([
					'error'             => 'invalid_token',
					'error_description' => 'The access token has been revoked.',
				]) ?: '{}')));
		}

		return $handler->handle($validated);
	}
}
