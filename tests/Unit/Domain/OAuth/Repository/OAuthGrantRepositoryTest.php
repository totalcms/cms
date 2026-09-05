<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;

final class OAuthGrantRepositoryTest extends TestCase
{
	private string $tmpFile;

	protected function setUp(): void
	{
		$this->tmpFile = sys_get_temp_dir() . '/oauth-grants-' . uniqid() . '.json';
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	public function testFindsGrantById(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1'));

		$loaded = $repo->find('g-1');
		$this->assertNotNull($loaded);
		$this->assertSame('g-1', $loaded->id);
	}

	public function testFindReturnsNullForUnknown(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$this->assertNull($repo->find('not-there'));
	}

	public function testFindByRefreshTokenHash(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1', null, null, 'hash-abc'));
		$repo->save($this->makeGrant('g-2', null, null, 'hash-xyz'));

		$found = $repo->findByRefreshTokenHash('hash-abc');
		$this->assertNotNull($found);
		$this->assertSame('g-1', $found->id);

		$this->assertNull($repo->findByRefreshTokenHash('hash-unknown'));
	}

	public function testFindByClientIdReturnsAllMatching(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1', 'client-A'));
		$repo->save($this->makeGrant('g-2', 'client-A'));
		$repo->save($this->makeGrant('g-3', 'client-B'));

		$results = $repo->findByClientId('client-A');
		$this->assertCount(2, $results);

		$ids = array_map(static fn (OAuthGrantData $g): string => $g->id, $results);
		$this->assertContains('g-1', $ids);
		$this->assertContains('g-2', $ids);
	}

	public function testListsAllGrants(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1'));
		$repo->save($this->makeGrant('g-2'));
		$repo->save($this->makeGrant('g-3'));

		$all = $repo->all();
		$this->assertCount(3, $all);
	}

	public function testDeletesGrant(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1'));
		$repo->save($this->makeGrant('g-2'));
		$repo->delete('g-1');

		$this->assertNull($repo->find('g-1'));
		$this->assertNotNull($repo->find('g-2'));
	}

	public function testDeleteByClientIdRemovesAllForClient(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1', 'client-A'));
		$repo->save($this->makeGrant('g-2', 'client-A'));
		$repo->save($this->makeGrant('g-3', 'client-B'));

		$repo->deleteByClientId('client-A');

		$this->assertNull($repo->find('g-1'));
		$this->assertNull($repo->find('g-2'));
		$this->assertNotNull($repo->find('g-3'));
		$this->assertCount(1, $repo->all());
	}

	public function testPruneExpiredRemovesPastGrantsAndReturnsCount(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);

		// Expired grant — 1 hour in the past
		$repo->save($this->makeGrant('g-expired', null, '2000-01-01T00:00:00Z'));

		// Active grant — 1 hour in the future
		$future = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('+1 hour')
			->format(\DateTimeInterface::ATOM);
		$repo->save($this->makeGrant('g-active', null, $future));

		$removed = $repo->pruneExpired();

		$this->assertSame(1, $removed);
		$this->assertNull($repo->find('g-expired'));
		$this->assertNotNull($repo->find('g-active'));
	}

	public function testPruneExpiredReturnsZeroWhenNothingExpired(): void
	{
		$repo   = new OAuthGrantRepository($this->tmpFile);
		$future = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('+1 hour')
			->format(\DateTimeInterface::ATOM);
		$repo->save($this->makeGrant('g-1', null, $future));

		$this->assertSame(0, $repo->pruneExpired());
		$this->assertCount(1, $repo->all());
	}

	public function testSaveUpdatesExistingGrant(): void
	{
		$repo = new OAuthGrantRepository($this->tmpFile);
		$repo->save($this->makeGrant('g-1', 'client-A', null, 'old-hash'));

		$updated = new OAuthGrantData(
			id: 'g-1',
			clientId: 'client-A',
			userId: 'user-1',
			scopes: ['cms:read'],
			refreshTokenHash: 'new-hash',
			issuedAt: '2026-05-24T10:00:00Z',
			expiresAt: '2026-06-24T10:00:00Z',
		);
		$repo->save($updated);

		$all = $repo->all();
		$this->assertCount(1, $all);
		$this->assertSame('new-hash', $all[0]->refreshTokenHash);
	}

	private function makeGrant(
		string $id,
		?string $clientId = null,
		?string $expiresAt = null,
		?string $refreshTokenHash = null,
	): OAuthGrantData {
		return new OAuthGrantData(
			id: $id,
			clientId: $clientId ?? 'client-default',
			userId: 'user-1',
			scopes: ['cms:read'],
			refreshTokenHash: $refreshTokenHash ?? 'hash-' . $id,
			issuedAt: '2026-05-24T10:00:00Z',
			expiresAt: $expiresAt ?? '2026-06-24T10:00:00Z',
		);
	}

	/**
	 * Pins the contract: an unreadable store yields no records and no warning.
	 *
	 * Note this passes against the older stat-then-read form too, at least on
	 * macOS — it documents the guarantee rather than reproducing a specific
	 * failure.
	 */
	public function testAnUnreadableStoreDegradesQuietlyInsteadOfWarning(): void
	{
		file_put_contents($this->tmpFile, '{}');
		if (!@chmod($this->tmpFile, 0000) || is_readable($this->tmpFile)) {
			$this->markTestSkipped('chmod does not restrict reads here (running as root?)');
		}

		$warnings = [];
		set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
			// Honour `@` the way PHP's own handler does — a custom handler is
			// still invoked for suppressed diagnostics, so without this the
			// test cannot tell a suppressed read from an unsuppressed one.
			if ((error_reporting() & $no) === 0) {
				return true;
			}
			$warnings[] = $str;

			return true;
		});

		try {
			$repo = new OAuthGrantRepository($this->tmpFile);
			$this->assertSame([], $repo->all());
		} finally {
			restore_error_handler();
			@chmod($this->tmpFile, 0644);
		}

		$this->assertSame([], $warnings, 'reading an unreadable store must not warn');
	}
}
