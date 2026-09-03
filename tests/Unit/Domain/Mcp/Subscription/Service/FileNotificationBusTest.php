<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Subscription\Service;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Subscription\Service\FileNotificationBus;

final class FileNotificationBusTest extends TestCase
{
	private string $busFile;

	protected function setUp(): void
	{
		$this->busFile = sys_get_temp_dir() . '/mcp_bus_test_' . uniqid('', true) . '.json';
	}

	protected function tearDown(): void
	{
		foreach ([$this->busFile, $this->busFile . '.lock', $this->busFile . '.tmp'] as $f) {
			if (file_exists($f)) {
				unlink($f);
			}
		}
	}

	private function bus(int $backlog = 256, int $ttl = 120): FileNotificationBus
	{
		return new FileNotificationBus($this->busFile, $backlog, $ttl);
	}

	// -------------------------------------------------------------------------
	// Cursor semantics
	// -------------------------------------------------------------------------

	public function testCursorIsZeroWhenNothingHasBeenPublished(): void
	{
		$this->assertSame(0, $this->bus()->cursor());
	}

	public function testCursorAdvancesOncePerPublish(): void
	{
		$bus = $this->bus();
		$bus->publish(new ResourceUpdatedNotification('tcms://blog/'));
		$bus->publish(new ResourceUpdatedNotification('tcms://news/'));

		$this->assertSame(2, $bus->cursor());
	}

	public function testAStreamOpeningNowSeesNothingPublishedBefore(): void
	{
		// "From now, not from the beginning" — a subscriber wants what happens
		// next, not a replay of the server's history.
		$bus = $this->bus();
		$bus->publish(new ResourceUpdatedNotification('tcms://blog/'));

		$cursor = $bus->cursor();
		[$found, $next] = $bus->since($cursor);

		$this->assertSame([], $found);
		$this->assertSame($cursor, $next);
	}

	// -------------------------------------------------------------------------
	// Delivery
	// -------------------------------------------------------------------------

	public function testSinceReturnsNotificationsPublishedAfterTheCursor(): void
	{
		$bus    = $this->bus();
		$cursor = $bus->cursor();

		$bus->publish(new ResourceUpdatedNotification('tcms://blog/'));
		$bus->publish(new ResourceUpdatedNotification('tcms://news/'));

		[$found, $next] = $bus->since($cursor);

		$this->assertCount(2, $found);
		$this->assertContainsOnlyInstancesOf(ResourceUpdatedNotification::class, $found);
		$this->assertSame(['tcms://blog/', 'tcms://news/'], array_map(
			static fn (ResourceUpdatedNotification $n): string => $n->uri,
			$found,
		));
		$this->assertSame(2, $next);
	}

	public function testReadingTwiceDoesNotRedeliver(): void
	{
		$bus    = $this->bus();
		$cursor = $bus->cursor();

		$bus->publish(new ResourceUpdatedNotification('tcms://blog/'));

		[$first, $next] = $bus->since($cursor);
		[$second, $_]   = $bus->since($next);

		$this->assertCount(1, $first);
		$this->assertSame([], $second);
	}

	public function testNonResourceNotificationsSurviveTheRoundTrip(): void
	{
		// The bus is type-agnostic; NotificationFilter decides what a given
		// stream carries. Losing a type here would silently break that.
		$bus    = $this->bus();
		$cursor = $bus->cursor();

		$bus->publish(new ToolListChangedNotification());

		[$found, $_] = $bus->since($cursor);

		$this->assertCount(1, $found);
		$this->assertInstanceOf(ToolListChangedNotification::class, $found[0]);
	}

	// -------------------------------------------------------------------------
	// Cross-process behaviour: the whole reason this is file-backed
	// -------------------------------------------------------------------------

	public function testASecondInstanceOnTheSameFileSeesTheFirstsPublishes(): void
	{
		// Stands in for two PHP-FPM workers: the request that writes an object
		// and the request holding a listen stream open are never the same
		// process, so the bus has to live in shared storage.
		$writer = $this->bus();
		$reader = $this->bus();

		$cursor = $reader->cursor();
		$writer->publish(new ResourceUpdatedNotification('tcms://blog/'));

		[$found, $_] = $reader->since($cursor);

		$this->assertCount(1, $found);
		$this->assertSame('tcms://blog/', $found[0]->uri);
	}

	// -------------------------------------------------------------------------
	// Bounded growth
	// -------------------------------------------------------------------------

	public function testTheBacklogIsBounded(): void
	{
		$bus = $this->bus(backlog: 3);

		for ($i = 0; $i < 10; $i++) {
			$bus->publish(new ResourceUpdatedNotification('tcms://c' . $i . '/'));
		}

		// A reader that fell far behind reads what is still there, not
		// everything it missed.
		[$found, $next] = $bus->since(0);

		$this->assertCount(3, $found);
		$this->assertSame(['tcms://c7/', 'tcms://c8/', 'tcms://c9/'], array_map(
			static fn (ResourceUpdatedNotification $n): string => $n->uri,
			$found,
		));
		$this->assertSame(10, $next);
	}

	public function testEntriesOlderThanTheTtlAreDropped(): void
	{
		// A reader that fell behind must not be handed stale notifications: the
		// TTL bounds how far back the buffer stays meaningful. Back-date the
		// entry rather than sleeping.
		$bus    = $this->bus(ttl: 60);
		$cursor = $bus->cursor();

		$bus->publish(new ResourceUpdatedNotification('tcms://blog/'));
		$bus->publish(new ResourceUpdatedNotification('tcms://news/'));

		$raw                     = json_decode((string)file_get_contents($this->busFile), true);
		$raw['entries'][0]['at'] = time() - 3600;
		file_put_contents($this->busFile, json_encode($raw));

		[$found, $next] = $bus->since($cursor);

		$this->assertCount(1, $found, 'The expired entry should have been dropped.');
		$this->assertSame('tcms://news/', $found[0]->uri);
		$this->assertSame(2, $next, 'The cursor must still advance past an expired entry.');
	}

	// -------------------------------------------------------------------------
	// Robustness — a broken bus must not break the stream
	// -------------------------------------------------------------------------

	public function testACorruptFileReadsAsEmptyRatherThanThrowing(): void
	{
		file_put_contents($this->busFile, '{not json');

		$bus = $this->bus();

		$this->assertSame(0, $bus->cursor());
		$this->assertSame([[], 0], $bus->since(0));
	}

	public function testAnUnreadableEntryIsSkippedRatherThanFailingTheBatch(): void
	{
		$bus    = $this->bus();
		$cursor = $bus->cursor();
		$bus->publish(new ResourceUpdatedNotification('tcms://blog/'));

		// Corrupt just the one entry, leaving the envelope intact.
		$raw                        = json_decode((string)file_get_contents($this->busFile), true);
		$raw['entries'][0]['frame'] = 'not-a-json-rpc-frame';
		file_put_contents($this->busFile, json_encode($raw));

		[$found, $next] = $bus->since($cursor);

		$this->assertSame([], $found);
		$this->assertSame(1, $next, 'The cursor must still advance past a bad entry.');
	}
}
