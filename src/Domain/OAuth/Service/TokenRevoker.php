<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use Defuse\Crypto\Crypto;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\RegisteredClaims;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;

/**
 * RFC 7009 revocation for one client's token. A refresh token is League's
 * Defuse-encrypted JSON payload: decrypt it with the server's key, hash the
 * refresh_token_id, drop the grant. An access token is a JWT: revoke its jti.
 * The hint only shortcuts detection — a mis-hinted token is still handled —
 * and an unknown or foreign token is silently a no-op, so the caller can
 * always answer 200 without leaking whether the token existed.
 */
readonly class TokenRevoker
{
	public const REFRESH_TOKEN = 'refresh_token';
	public const ACCESS_TOKEN  = 'access_token';

	public function __construct(
		private OAuthGrantRepository $grants,
		private OAuthRevocationList $revocationList,
		private OAuthActivityLogger $activityLogger,
		private OAuthServerFactory $serverFactory,
	) {
	}

	/** @return string|null The type revoked, or null when nothing matched */
	public function revoke(string $token, string $hint, OAuthClientData $client): ?string
	{
		if (($hint === self::REFRESH_TOKEN || $hint === '') && $this->revokeRefreshToken($token, $client)) {
			return self::REFRESH_TOKEN;
		}

		if (($hint === self::ACCESS_TOKEN || $hint === '') && $this->revokeAccessToken($token, $client)) {
			return self::ACCESS_TOKEN;
		}

		return null;
	}

	private function revokeRefreshToken(string $token, OAuthClientData $client): bool
	{
		$refreshId = $this->refreshTokenId($token);
		if ($refreshId === null) {
			return false;
		}

		$grant = $this->grants->findByRefreshTokenHash(hash('sha256', $refreshId));
		if (!$grant instanceof OAuthGrantData || $grant->clientId !== $client->id) {
			return false;
		}

		$this->grants->delete($grant->id);
		$this->activityLogger->tokenRevoked($client->id, self::REFRESH_TOKEN, $grant->id);

		return true;
	}

	private function revokeAccessToken(string $token, OAuthClientData $client): bool
	{
		if ($token === '') {
			return false;
		}

		try {
			$jwt = (new Parser(new JoseEncoder()))->parse($token);
		} catch (\Throwable) {
			return false;
		}

		if (!$jwt instanceof Plain) {
			return false;
		}

		// League sets the client id as the `aud` claim via permittedFor(); the
		// token must belong to the client asking. isPermittedFor() needs a
		// non-empty audience.
		$jti = (string)$jwt->claims()->get(RegisteredClaims::ID, '');
		if ($jti === '' || $client->id === '' || !$jwt->isPermittedFor($client->id)) {
			return false;
		}

		$this->revocationList->revoke($jti);
		$this->activityLogger->tokenRevoked($client->id, self::ACCESS_TOKEN, $jti);

		return true;
	}

	/** The refresh_token_id inside an opaque refresh token, or null when it is not one of ours. */
	private function refreshTokenId(string $encryptedToken): ?string
	{
		try {
			$plaintext = Crypto::decryptWithPassword($encryptedToken, $this->serverFactory->encryptionKey());
			$payload   = json_decode($plaintext, true);
		} catch (\Throwable) {
			return null;
		}

		return is_array($payload) && isset($payload['refresh_token_id']) ? (string)$payload['refresh_token_id'] : null;
	}
}
