<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;

final class OAuthClientRepositoryTest extends TestCase
{
	private string $tmpFile;

	protected function setUp(): void
	{
		$this->tmpFile = sys_get_temp_dir() . '/oauth-clients-' . uniqid() . '.json';
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	public function testFindsClientById(): void
	{
		$repo   = new OAuthClientRepository($this->tmpFile);
		$client = $this->makeClient('c-1');
		$repo->save($client);

		$loaded = $repo->find('c-1');
		$this->assertNotNull($loaded);
		$this->assertSame('c-1', $loaded->id);
	}

	public function testFindReturnsNullForUnknown(): void
	{
		$repo = new OAuthClientRepository($this->tmpFile);
		$this->assertNull($repo->find('not-there'));
	}

	public function testListsAllClients(): void
	{
		$repo = new OAuthClientRepository($this->tmpFile);
		$repo->save($this->makeClient('c-1'));
		$repo->save($this->makeClient('c-2'));

		$all = $repo->all();
		$this->assertCount(2, $all);
	}

	public function testDeletesClient(): void
	{
		$repo = new OAuthClientRepository($this->tmpFile);
		$repo->save($this->makeClient('c-1'));
		$repo->delete('c-1');

		$this->assertNull($repo->find('c-1'));
	}

	public function testSaveUpdatesExistingClient(): void
	{
		$repo = new OAuthClientRepository($this->tmpFile);
		$repo->save($this->makeClient('c-1'));

		$updated = new OAuthClientData(
			id: 'c-1',
			name: 'Updated Name',
			secretHash: 'newhash',
			redirectUris: ['https://updated.test/cb'],
			scopes: ['cms:write'],
			isDynamic: true,
			isConfidential: false,
			createdAt: '2026-05-24T10:00:00Z',
			createdBy: 'admin',
		);
		$repo->save($updated);

		$all = $repo->all();
		$this->assertCount(1, $all);
		$this->assertSame('Updated Name', $all[0]->name);
	}

	public function testDeleteNonExistentClientIsNoOp(): void
	{
		$repo = new OAuthClientRepository($this->tmpFile);
		$repo->save($this->makeClient('c-1'));
		$repo->delete('does-not-exist');

		$this->assertCount(1, $repo->all());
	}

	private function makeClient(string $id): OAuthClientData
	{
		return new OAuthClientData(
			id: $id,
			name: 'Test ' . $id,
			secretHash: 'hash',
			redirectUris: ['https://x.test/cb'],
			scopes: ['cms:read'],
			isDynamic: false,
			isConfidential: true,
			createdAt: '2026-05-24T10:00:00Z',
			createdBy: 'admin',
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
			$repo = new OAuthClientRepository($this->tmpFile);
			$this->assertSame([], $repo->all());
		} finally {
			restore_error_handler();
			@chmod($this->tmpFile, 0644);
		}

		$this->assertSame([], $warnings, 'reading an unreadable store must not warn');
	}
}
