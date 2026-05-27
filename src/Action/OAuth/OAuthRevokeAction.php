<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Defuse\Crypto\Crypto;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\RegisteredClaims;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * RFC 7009 token revocation. Per spec:
 *   - Accept `token` (required) + `token_type_hint` (optional)
 *   - Always 200 on success OR unknown token (don't leak token-existence info)
 *   - 400 if `token` missing; 401 if client auth fails
 *
 * Token type detection:
 *   - Try as opaque refresh token first (when hint = 'refresh_token' or no hint):
 *     decrypt with the server's encryption key, extract refresh_token_id,
 *     hash it and look up the grant.
 *   - Try as JWT access token (when hint = 'access_token' or no hint):
 *     parse the JWT, extract the jti, add to revocation list.
 *
 * `token_type_hint` (when present) shortcuts type detection but we
 * verify regardless — clients are allowed to lie / get it wrong.
 *
 * Client authentication: `client_id` + `client_secret` in POST body
 * (RFC 7009 §2.1). Basic auth header is also acceptable per RFC but
 * v1 only supports body params.
 */
readonly class OAuthRevokeAction
{
	public function __construct(
		private OAuthClientRepository $clients,
		private OAuthGrantRepository $grants,
		private OAuthRevocationList $revocationList,
		private JsonRenderer $renderer,
		private Config $config,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = (array)($request->getParsedBody() ?? []);
		if ($body === []) {
			parse_str((string)$request->getBody(), $body);
		}

		$token        = (string)($body['token'] ?? '');
		$hint         = (string)($body['token_type_hint'] ?? '');
		$clientId     = (string)($body['client_id'] ?? '');
		$clientSecret = (string)($body['client_secret'] ?? '');

		if ($token === '') {
			return $this->renderer->json($response, [
				'error'             => 'invalid_request',
				'error_description' => 'token parameter is required',
			], 400);
		}

		// Authenticate the client (required per RFC 7009 §2.1 for confidential clients).
		$client = $this->clients->find($clientId);
		if ($client === null || !password_verify($clientSecret, $client->secretHash)) {
			return $this->renderer->json($response, [
				'error'             => 'invalid_client',
				'error_description' => 'client authentication failed',
			], 401);
		}

		// Attempt revocation by type. The hint shortcuts detection but we
		// still fall through so a mis-hinted token gets handled correctly.
		// Per RFC 7009 §2.2: always return 200 regardless of whether the
		// token existed — do not leak token-existence information.

		if ($hint === 'refresh_token' || $hint === '') {
			$refreshId = $this->extractRefreshTokenId($token);
			if ($refreshId !== null) {
				$hash  = hash('sha256', $refreshId);
				$grant = $this->grants->findByRefreshTokenHash($hash);
				if ($grant !== null && $grant->clientId === $client->id) {
					$this->grants->delete($grant->id);
					$this->activityLogger->tokenRevoked($client->id, 'refresh_token', $grant->id);
					return $response->withStatus(200);
				}
			}
		}

		if ($hint === 'access_token' || $hint === '') {
			$parser = new Parser(new JoseEncoder());
			try {
				$jwt = $parser->parse($token);

				// Parser::parse() returns Token interface; Plain is the concrete
				// implementation that carries claims(). Unsupported or unsigned
				// token types are rejected by the catch below.
				if (!($jwt instanceof Plain)) {
					// Not a plain JWT — fall through to 200.
					return $response->withStatus(200);
				}

				$jti = (string)$jwt->claims()->get(RegisteredClaims::ID, '');

				// isPermittedFor() requires a non-empty-string for the audience.
				// Verify the token belongs to the requesting client.
				// League sets the client id as the `aud` claim via permittedFor().
				if ($jti !== '' && $client->id !== '' && $jwt->isPermittedFor($client->id)) {
					$this->revocationList->revoke($jti);
					$this->activityLogger->tokenRevoked($client->id, 'access_token', $jti);
				}
			} catch (\Throwable) {
				// Token didn't parse as JWT. Per RFC 7009 §2.2, return 200 anyway.
			}
		}

		return $response->withStatus(200);
	}

	/**
	 * Decrypt an opaque refresh token and return the refresh_token_id it carries.
	 * Returns null if decryption or JSON parsing fails (token is not a valid
	 * refresh token from this server).
	 *
	 * League stores refresh tokens as Defuse-encrypted JSON payloads:
	 *   {"client_id":"...","refresh_token_id":"...","scopes":[...],"user_id":"...","expire_time":...}
	 * The encryption key is the SHA-256 hex digest of the signing PEM (see OAuthServerFactory).
	 */
	private function extractRefreshTokenId(string $encryptedToken): ?string
	{
		$keyPath = (string)$this->config->oauth['signingKeyPath'];
		if (!is_file($keyPath)) {
			return null;
		}

		$pem = (string)file_get_contents($keyPath);
		$encryptionKey = hash('sha256', $pem);

		try {
			$plaintext = Crypto::decryptWithPassword($encryptedToken, $encryptionKey);
			$payload   = json_decode($plaintext, true);
			if (!is_array($payload) || !isset($payload['refresh_token_id'])) {
				return null;
			}
			return (string)$payload['refresh_token_id'];
		} catch (\Throwable) {
			return null;
		}
	}
}
