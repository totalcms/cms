<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;

/**
 * Removes stale RFC 7591 self-registered clients. MCP clients (claude.ai,
 * Claude Desktop, Cursor) re-register on every connector add, so failed or
 * abandoned connection attempts each leave behind a client record that will
 * never be used again.
 *
 * A dynamic client is stale when it has no active grant — every completed
 * connection produces a grant at consent, and an expired grant means the
 * refresh window lapsed without the connector coming back. Clients younger
 * than the retention window are kept regardless: a registration with no
 * grant yet may simply have its user still on the consent screen.
 *
 * Hand-registered (static) clients are never touched — they hold issued
 * credentials an operator may be about to configure somewhere.
 */
final readonly class OAuthClientPruner
{
	private const RETENTION = 'PT24H';

	/** How long between opportunistic GC runs. See maybeRunDaily(). */
	private const GC_INTERVAL = 86400;

	public function __construct(
		private OAuthClientRepository $clients,
		private OAuthGrantRepository $grants,
		private string $gcMarkerPath,
	) {
	}

	/**
	 * Opportunistic daily GC, called from the token and register actions so
	 * OAuth storage stays clean without operator-configured cron — the same
	 * marker-file throttle the job queue uses for its daily VACUUM. Sites
	 * that never use OAuth never pay the cost because the hooks never fire.
	 *
	 * Runs the full sweep (expired grants + stale dynamic clients) at most
	 * once per day. Failures are swallowed: hygiene must never break the
	 * token issuance or registration that happened to trigger it.
	 */
	public function maybeRunDaily(): void
	{
		try {
			$last = file_exists($this->gcMarkerPath) ? (int)filemtime($this->gcMarkerPath) : 0;
			if ($last > 0 && (time() - $last) < self::GC_INTERVAL) {
				return;
			}

			$this->grants->pruneExpired();
			$this->pruneStaleDynamicClients();
			touch($this->gcMarkerPath);
		} catch (\Throwable) {
			// Skipped this pass; the next qualifying request retries.
		}
	}

	/**
	 * @return list<OAuthClientData> The clients that were removed
	 */
	public function pruneStaleDynamicClients(?\DateTimeImmutable $now = null): array
	{
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$cutoff = $now->sub(new \DateInterval(self::RETENTION));

		$removed = [];
		foreach ($this->clients->all() as $client) {
			if (!$client->isDynamic) {
				continue;
			}
			if (!$this->isBefore($client->createdAt, $cutoff)) {
				continue;
			}
			if ($this->hasActiveGrant($client->id, $now)) {
				continue;
			}

			// Cascade expired-grant leftovers before the client itself.
			$this->grants->deleteByClientId($client->id);
			$this->clients->delete($client->id);
			$removed[] = $client;
		}

		return $removed;
	}

	private function hasActiveGrant(string $clientId, \DateTimeImmutable $now): bool
	{
		foreach ($this->grants->findByClientId($clientId) as $grant) {
			$expiry = $this->parse($grant->expiresAt);
			if ($expiry instanceof \DateTimeImmutable && $expiry > $now) {
				return true;
			}
		}

		return false;
	}

	private function isBefore(string $timestamp, \DateTimeImmutable $cutoff): bool
	{
		$parsed = $this->parse($timestamp);

		// An unparseable created_at can't prove the client is inside the
		// retention window; with no active grant either, it's removable.
		return !$parsed instanceof \DateTimeImmutable || $parsed < $cutoff;
	}

	private function parse(string $timestamp): ?\DateTimeImmutable
	{
		if ($timestamp === '') {
			return null;
		}
		try {
			return new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
	}
}
