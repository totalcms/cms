<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthClientPruner;

final class OAuthClientPrunerTest extends TestCase
{
	private string $tmpDir;
	private OAuthClientRepository $clients;
	private OAuthGrantRepository $grants;
	private OAuthClientPruner $pruner;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/oauth-pruner-test-' . uniqid('', true);
		mkdir($this->tmpDir, 0700, true);

		$this->clients = new OAuthClientRepository($this->tmpDir . '/oauth-clients.json');
		$this->grants  = new OAuthGrantRepository($this->tmpDir . '/oauth-grants.json');
		$this->pruner  = new OAuthClientPruner($this->clients, $this->grants, $this->tmpDir . '/.oauth-gc');
	}

	protected function tearDown(): void
	{
		// Two globs: '*' skips dotfiles, and the GC marker is one.
		$files = array_merge((array)glob($this->tmpDir . '/*'), (array)glob($this->tmpDir . '/.[!.]*'));
		foreach ($files as $file) {
			if (is_string($file)) {
				@unlink($file);
			}
		}
		@rmdir($this->tmpDir);
	}

	private function makeClient(string $id, bool $isDynamic, string $createdAt): void
	{
		$this->clients->save(new OAuthClientData(
			id: $id,
			name: 'Client ' . $id,
			secretHash: password_hash('secret', PASSWORD_BCRYPT),
			redirectUris: ['https://example.test/cb'],
			scopes: ['mcp:tools'],
			isDynamic: $isDynamic,
			isConfidential: true,
			createdAt: $createdAt,
			createdBy: $isDynamic ? 'rfc7591-self-registered' : 'admin',
		));
	}

	private function makeGrant(string $id, string $clientId, string $expiresAt): void
	{
		$this->grants->save(new OAuthGrantData(
			id: $id,
			clientId: $clientId,
			userId: 'admin@example.test',
			scopes: ['mcp:tools'],
			refreshTokenHash: hash('sha256', $id),
			issuedAt: gmdate('c', time() - 3600),
			expiresAt: $expiresAt,
		));
	}

	private function daysAgo(int $days): string
	{
		return gmdate('c', time() - $days * 86400);
	}

	private function daysAhead(int $days): string
	{
		return gmdate('c', time() + $days * 86400);
	}

	public function testOldDynamicClientWithNoGrantsIsPruned(): void
	{
		$this->makeClient('dead-registration', true, $this->daysAgo(3));

		$removed = $this->pruner->pruneStaleDynamicClients();

		$this->assertCount(1, $removed);
		$this->assertSame('dead-registration', $removed[0]->id);
		$this->assertNull($this->clients->find('dead-registration'));
	}

	public function testOldDynamicClientWithOnlyExpiredGrantsIsPrunedWithItsGrants(): void
	{
		$this->makeClient('idle-connector', true, $this->daysAgo(60));
		$this->makeGrant('g1', 'idle-connector', $this->daysAgo(5));

		$removed = $this->pruner->pruneStaleDynamicClients();

		$this->assertCount(1, $removed);
		$this->assertNull($this->clients->find('idle-connector'));
		$this->assertSame([], $this->grants->findByClientId('idle-connector'));
	}

	public function testDynamicClientWithActiveGrantIsKept(): void
	{
		$this->makeClient('live-connector', true, $this->daysAgo(60));
		$this->makeGrant('g2', 'live-connector', $this->daysAhead(20));

		$removed = $this->pruner->pruneStaleDynamicClients();

		$this->assertCount(0, $removed);
		$this->assertNotNull($this->clients->find('live-connector'));
		$this->assertCount(1, $this->grants->findByClientId('live-connector'));
	}

	public function testFreshDynamicClientIsKeptWhileConsentMayBeInFlight(): void
	{
		// Registered minutes ago, no grant yet — the user may still be on the
		// consent screen. The retention window must protect it.
		$this->makeClient('mid-handshake', true, gmdate('c', time() - 300));

		$removed = $this->pruner->pruneStaleDynamicClients();

		$this->assertCount(0, $removed);
		$this->assertNotNull($this->clients->find('mid-handshake'));
	}

	public function testStaticClientIsNeverPruned(): void
	{
		$this->makeClient('hand-registered', false, $this->daysAgo(365));

		$removed = $this->pruner->pruneStaleDynamicClients();

		$this->assertCount(0, $removed);
		$this->assertNotNull($this->clients->find('hand-registered'));
	}

	public function testMixedPopulationOnlyStaleDynamicsAreRemoved(): void
	{
		$this->makeClient('static-old', false, $this->daysAgo(365));
		$this->makeClient('dynamic-live', true, $this->daysAgo(30));
		$this->makeGrant('g3', 'dynamic-live', $this->daysAhead(10));
		$this->makeClient('dynamic-dead-1', true, $this->daysAgo(2));
		$this->makeClient('dynamic-dead-2', true, $this->daysAgo(2));
		$this->makeGrant('g4', 'dynamic-dead-2', $this->daysAgo(1));

		$removed    = $this->pruner->pruneStaleDynamicClients();
		$removedIds = array_map(static fn (OAuthClientData $c): string => $c->id, $removed);
		sort($removedIds);

		$this->assertSame(['dynamic-dead-1', 'dynamic-dead-2'], $removedIds);
		$this->assertNotNull($this->clients->find('static-old'));
		$this->assertNotNull($this->clients->find('dynamic-live'));
	}

	// ── maybeRunDaily throttle ─────────────────────────────────────────────

	public function testMaybeRunDailyPrunesAndStampsTheMarker(): void
	{
		$this->makeClient('dead-registration', true, $this->daysAgo(3));
		$this->makeGrant('expired-orphan', 'gone-client', $this->daysAgo(5));

		$this->pruner->maybeRunDaily();

		$this->assertNull($this->clients->find('dead-registration'));
		$this->assertNull($this->grants->find('expired-orphan'));
		$this->assertFileExists($this->tmpDir . '/.oauth-gc');
	}

	public function testMaybeRunDailySkipsWithinTheInterval(): void
	{
		$this->pruner->maybeRunDaily();

		// Litter appearing after a fresh run must survive until the next day.
		$this->makeClient('new-litter', true, $this->daysAgo(3));
		$this->pruner->maybeRunDaily();

		$this->assertNotNull($this->clients->find('new-litter'));
	}

	public function testMaybeRunDailyRunsAgainAfterTheIntervalLapses(): void
	{
		$this->pruner->maybeRunDaily();
		$this->makeClient('new-litter', true, $this->daysAgo(3));

		touch($this->tmpDir . '/.oauth-gc', time() - 90000);
		$this->pruner->maybeRunDaily();

		$this->assertNull($this->clients->find('new-litter'));
	}
}
