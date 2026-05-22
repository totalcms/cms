<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Subscription\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Subscription\Service\SubscriptionIndex;

final class SubscriptionIndexTest extends TestCase
{
	private string $indexFile;
	private SubscriptionIndex $index;

	protected function setUp(): void
	{
		$this->indexFile = sys_get_temp_dir() . '/subscription_index_test_' . uniqid('', true) . '.json';
		$this->index     = new SubscriptionIndex($this->indexFile);
	}

	protected function tearDown(): void
	{
		foreach ([$this->indexFile, $this->indexFile . '.lock', $this->indexFile . '.tmp'] as $f) {
			if (file_exists($f)) {
				unlink($f);
			}
		}
	}

	// -------------------------------------------------------------------------
	// 1. Empty / missing file
	// -------------------------------------------------------------------------

	public function testSubscribersOfReturnEmptyArrayWhenFileDoesNotExist(): void
	{
		$this->assertSame([], $this->index->subscribersOf('tcms://blog/'));
	}

	// -------------------------------------------------------------------------
	// 2. Basic add → subscribersOf
	// -------------------------------------------------------------------------

	public function testAddThenSubscribersOfReturnsSessionId(): void
	{
		$this->index->add('tcms://blog/', 'session-1');

		$this->assertSame(['session-1'], $this->index->subscribersOf('tcms://blog/'));
	}

	// -------------------------------------------------------------------------
	// 3. Multiple sessions on same URI
	// -------------------------------------------------------------------------

	public function testAddMultipleSessionsThenSubscribersOfReturnsAll(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->add('tcms://blog/', 'session-2');
		$this->index->add('tcms://blog/', 'session-3');

		$result = $this->index->subscribersOf('tcms://blog/');
		sort($result);
		$this->assertSame(['session-1', 'session-2', 'session-3'], $result);
	}

	// -------------------------------------------------------------------------
	// 4. Duplicate add → deduplication
	// -------------------------------------------------------------------------

	public function testAddSameSessionTwiceIsDeduplicatedInSubscribersOf(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->add('tcms://blog/', 'session-1');

		$this->assertSame(['session-1'], $this->index->subscribersOf('tcms://blog/'));
	}

	// -------------------------------------------------------------------------
	// 5. Remove one, leave others
	// -------------------------------------------------------------------------

	public function testRemoveOneSessionLeavesOthersIntact(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->add('tcms://blog/', 'session-2');
		$this->index->remove('tcms://blog/', 'session-1');

		$this->assertSame(['session-2'], $this->index->subscribersOf('tcms://blog/'));
	}

	// -------------------------------------------------------------------------
	// 6. Remove last subscriber → URI key is dropped from the file
	// -------------------------------------------------------------------------

	public function testRemoveLastSubscriberDropsUriKeyFromFile(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->remove('tcms://blog/', 'session-1');

		// subscribersOf must return []
		$this->assertSame([], $this->index->subscribersOf('tcms://blog/'));

		// The raw JSON file must NOT contain the URI key at all
		$raw     = (string)file_get_contents($this->indexFile);
		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded);
		$this->assertArrayNotHasKey('tcms://blog/', $decoded);
	}

	// -------------------------------------------------------------------------
	// 7. Remove non-existent session → no error, no change
	// -------------------------------------------------------------------------

	public function testRemoveNonExistentSessionIsNoOp(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->remove('tcms://blog/', 'ghost-session');

		$this->assertSame(['session-1'], $this->index->subscribersOf('tcms://blog/'));
	}

	public function testRemoveFromMissingUriIsNoOp(): void
	{
		// No add at all — must not throw
		$this->index->remove('tcms://nonexistent/', 'session-1');

		$this->assertSame([], $this->index->subscribersOf('tcms://nonexistent/'));
	}

	// -------------------------------------------------------------------------
	// 8. purgeSession removes a session from every URI
	// -------------------------------------------------------------------------

	public function testPurgeSessionRemovesSessionFromAllUris(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->add('tcms://blog/', 'session-2');
		$this->index->add('tcms://products/', 'session-1');
		$this->index->add('tcms://products/', 'session-3');

		$this->index->purgeSession('session-1');

		$this->assertSame(['session-2'], $this->index->subscribersOf('tcms://blog/'));
		$this->assertSame(['session-3'], $this->index->subscribersOf('tcms://products/'));
	}

	public function testPurgeSessionDropsEmptyUriKeys(): void
	{
		$this->index->add('tcms://blog/', 'session-only');
		$this->index->purgeSession('session-only');

		$this->assertSame([], $this->index->subscribersOf('tcms://blog/'));

		$raw     = (string)file_get_contents($this->indexFile);
		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded);
		$this->assertArrayNotHasKey('tcms://blog/', $decoded);
	}

	public function testPurgeSessionOnGhostSessionIsNoOp(): void
	{
		// Purging a session that never subscribed leaves the index untouched —
		// session expiry can call this for sessions that didn't hold any
		// subscriptions and the call must not throw or wipe other data.
		$this->index->add('tcms://blog/', 'session-real');
		$this->index->purgeSession('session-ghost');

		$this->assertSame(['session-real'], $this->index->subscribersOf('tcms://blog/'));
	}

	// -------------------------------------------------------------------------
	// 9. clear() empties everything
	// -------------------------------------------------------------------------

	public function testClearEmptiesAllSubscriptions(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->add('tcms://products/', 'session-2');

		$this->index->clear();

		$this->assertSame([], $this->index->subscribersOf('tcms://blog/'));
		$this->assertSame([], $this->index->subscribersOf('tcms://products/'));

		$raw     = (string)file_get_contents($this->indexFile);
		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded);
		$this->assertSame([], $decoded);
	}

	// -------------------------------------------------------------------------
	// 10. Concurrency smoke — two sequential adds don't lose writes
	// -------------------------------------------------------------------------

	public function testTwoSequentialAddsToSameUriAreNotLost(): void
	{
		$this->index->add('tcms://blog/', 'session-A');
		$this->index->add('tcms://blog/', 'session-B');

		$result = $this->index->subscribersOf('tcms://blog/');
		sort($result);
		$this->assertSame(['session-A', 'session-B'], $result);
	}

	public function testTwoSequentialAddsAcrossDifferentUrisAreNotLost(): void
	{
		$this->index->add('tcms://blog/', 'session-1');
		$this->index->add('tcms://gallery/', 'session-2');

		$this->assertSame(['session-1'], $this->index->subscribersOf('tcms://blog/'));
		$this->assertSame(['session-2'], $this->index->subscribersOf('tcms://gallery/'));
	}
}
