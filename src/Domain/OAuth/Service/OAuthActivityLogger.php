<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use Psr\Log\LoggerInterface;

/**
 * Structured audit logging for OAuth events. Writes to oauth-activity.log
 * via T3's LoggerFactory (wired in container.php). Every event gets a
 * `type` field for the future observability dashboard to filter on.
 *
 * Security-relevant events (refresh_replay, rate_limit) log at warning
 * level; routine lifecycle events (client.created, token.issued) log at
 * info level. The dashboard reads this file; the file itself is the
 * authoritative record.
 */
final readonly class OAuthActivityLogger
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function clientCreated(string $clientId, string $name, bool $isDynamic, string $createdBy): void
	{
		$this->logger->info('OAuth client created', [
			'type'       => 'client.created',
			'client_id'  => $clientId,
			'name'       => $name,
			'is_dynamic' => $isDynamic,
			'created_by' => $createdBy,
		]);
	}

	public function clientDeleted(string $clientId, string $deletedBy): void
	{
		$this->logger->info('OAuth client deleted', [
			'type'       => 'client.deleted',
			'client_id'  => $clientId,
			'deleted_by' => $deletedBy,
		]);
	}

	/**
	 * @param list<string> $scopes
	 */
	public function consentGranted(string $clientId, string $userId, array $scopes): void
	{
		$this->logger->info('OAuth consent granted', [
			'type'      => 'consent.granted',
			'client_id' => $clientId,
			'user_id'   => $userId,
			'scopes'    => $scopes,
		]);
	}

	public function consentDenied(string $clientId, string $userId): void
	{
		$this->logger->info('OAuth consent denied', [
			'type'      => 'consent.denied',
			'client_id' => $clientId,
			'user_id'   => $userId,
		]);
	}

	/**
	 * @param list<string> $scopes
	 */
	public function tokenIssued(string $clientId, string $userId, array $scopes): void
	{
		$this->logger->info('OAuth token issued', [
			'type'      => 'token.issued',
			'client_id' => $clientId,
			'user_id'   => $userId,
			'scopes'    => $scopes,
		]);
	}

	public function tokenRefreshed(string $clientId, string $grantId): void
	{
		$this->logger->info('OAuth token refreshed', [
			'type'      => 'token.refreshed',
			'client_id' => $clientId,
			'grant_id'  => $grantId,
		]);
	}

	public function tokenRevoked(string $clientId, string $tokenType, string $tokenId): void
	{
		$this->logger->info('OAuth token revoked', [
			'type'       => 'token.revoked',
			'client_id'  => $clientId,
			'token_type' => $tokenType,
			'token_id'   => $tokenId,
		]);
	}

	public function refreshReplayDetected(string $clientId, string $grantId, string $tokenHash): void
	{
		$this->logger->warning('OAuth refresh token replay — chain revoked', [
			'type'       => 'security.refresh_replay',
			'client_id'  => $clientId,
			'grant_id'   => $grantId,
			'token_hash' => substr($tokenHash, 0, 8) . '…',
		]);
	}

	/**
	 * @param list<string> $tokenScopes
	 */
	public function scopeRejected(string $clientId, string $operation, array $tokenScopes): void
	{
		$this->logger->info('OAuth scope-rejected request', [
			'type'         => 'scope.rejected',
			'client_id'    => $clientId,
			'operation'    => $operation,
			'token_scopes' => $tokenScopes,
		]);
	}

	/**
	 * Logged when a Bearer-authenticated REST request passes the scope
	 * layer (OAuthRestScopeMiddleware) but is denied by the access-group
	 * layer (BaseAccessMiddleware) — the caller's identity resolves to a
	 * user whose access groups don't grant $operation. Distinct from
	 * scopeRejected(), which fires earlier for tokens whose scopes don't
	 * cover the operation at all.
	 */
	public function groupRejected(string $clientId, string $operation, string $userId): void
	{
		$this->logger->info('OAuth group-rejected request', [
			'type'      => 'group.rejected',
			'client_id' => $clientId,
			'operation' => $operation,
			'user_id'   => $userId,
		]);
	}

	public function dynamicRegistration(string $clientId, string $clientName, string $remoteAddr): void
	{
		$this->logger->info('OAuth dynamic client registered', [
			'type'        => 'client.dynamic_registered',
			'client_id'   => $clientId,
			'client_name' => $clientName,
			'remote_addr' => $remoteAddr,
		]);
	}

	public function rateLimitHit(string $endpoint, string $remoteAddr): void
	{
		$this->logger->warning('OAuth rate limit hit', [
			'type'        => 'security.rate_limit',
			'endpoint'    => $endpoint,
			'remote_addr' => $remoteAddr,
		]);
	}
}
