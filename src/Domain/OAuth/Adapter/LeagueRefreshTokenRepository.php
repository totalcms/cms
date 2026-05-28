<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;

/**
 * Bridges league's RefreshTokenRepositoryInterface to T3's OAuthGrantRepository.
 *
 * Refresh tokens are stored as grant records keyed by a SHA-256 hash of the
 * token identifier. Access tokens are stateless JWTs; the grant record carries
 * the client ID, user ID, scopes, and expiry for later introspection.
 */
final class LeagueRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
	public function __construct(
		private readonly OAuthGrantRepository $grants,
		private readonly OAuthReplayDetector $replayDetector,
		private readonly OAuthActivityLogger $activityLogger,
	) {
	}

	// @phpstan-ignore-next-line return.unusedType
	public function getNewRefreshToken(): ?RefreshTokenEntityInterface
	{
		// Nullable per interface contract; T3 never returns null here.
		return new LeagueRefreshTokenEntity();
	}

	public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
	{
		$accessToken    = $refreshTokenEntity->getAccessToken();
		$userIdentifier = $accessToken->getUserIdentifier();

		/** @var list<string> $scopes */
		$scopes = array_map(
			static fn ($s): string => $s->getIdentifier(),
			$accessToken->getScopes(),
		);

		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		$grant = new OAuthGrantData(
			id: $this->generateId(),
			clientId: $accessToken->getClient()->getIdentifier(),
			userId: $userIdentifier ?? '',
			scopes: $scopes,
			refreshTokenHash: hash('sha256', $refreshTokenEntity->getIdentifier()),
			issuedAt: $now->format(DATE_ATOM),
			expiresAt: $refreshTokenEntity->getExpiryDateTime()->format(DATE_ATOM),
		);

		$this->grants->save($grant);
	}

	public function revokeRefreshToken(string $tokenId): void
	{
		$hash  = hash('sha256', $tokenId);
		$grant = $this->grants->findByRefreshTokenHash($hash);
		if ($grant !== null) {
			$this->replayDetector->recordRotated($hash, $grant->clientId, $grant->userId);
			$this->grants->delete($grant->id);
		}
	}

	public function isRefreshTokenRevoked(string $tokenId): bool
	{
		$hash = hash('sha256', $tokenId);

		if ($this->grants->findByRefreshTokenHash($hash) !== null) {
			return false; // grant is live
		}

		// No live grant — check if this hash was previously rotated out.
		$rotated = $this->replayDetector->getRotatedIdentity($hash);
		if ($rotated !== null) {
			// REPLAY DETECTED. Revoke all grants for this client+user.
			$clientId = $rotated['client_id'];
			$userId   = $rotated['user_id'];

			$grantsForClient = $this->grants->findByClientId($clientId);
			$revokedGrantId  = null;
			foreach ($grantsForClient as $g) {
				if ($g->userId === $userId) {
					$this->grants->delete($g->id);
					$revokedGrantId = $revokedGrantId ?? $g->id;
				}
			}

			$this->activityLogger->refreshReplayDetected(
				$clientId,
				$revokedGrantId ?? '',
				$hash,
			);
		}

		return true; // either way, this specific token is revoked
	}

	/**
	 * Generate a random UUIDv4 string for the grant record id.
	 */
	private function generateId(): string
	{
		$bytes = random_bytes(16);
		// Set version 4 bits.
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		// Set variant bits.
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
