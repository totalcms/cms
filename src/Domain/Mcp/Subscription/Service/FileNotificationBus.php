<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Subscription\NotificationBusInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-backed notification bus for the modern (2026-07-28) protocol era.
 *
 * `subscriptions/listen` replaced `resources/subscribe` in that era: instead of
 * the server tracking who is subscribed to what, every open listen stream reads
 * forward from a shared log and filters for itself. This is the shared log.
 *
 * **Why a file and not the cache.** The SDK ships `Psr16NotificationBus`, and
 * feeding it a PSR-16 adapter over CacheManager would be less code. It would
 * also be wrong: T3's cache is APCu-first, and APCu is per-process. The request
 * that writes an object and the request holding a listen stream open are never
 * the same PHP-FPM worker, so an APCu-backed bus would publish into one worker's
 * memory and read from another's — silently delivering nothing, on exactly the
 * default configuration. Redis or Memcached would work; the filesystem works
 * everywhere. This follows SubscriptionIndex and the SDK's own FileSessionStore,
 * both of which live under tmpdir for the same reason.
 *
 * **Shape.** One JSON file holding a ring buffer plus a monotonic cursor:
 *
 *     {
 *         "cursor": 42,
 *         "entries": [
 *             {"seq": 40, "at": 1756900000, "frame": "{\"jsonrpc\":\"2.0\",...}"},
 *             {"seq": 41, "at": 1756900001, "frame": "..."}
 *         ]
 *     }
 *
 * `cursor` is the sequence the next publish will take, so it doubles as "where
 * a stream opening now should start from" — deliberately the head and not the
 * beginning, because a subscriber wants what happens next, not a replay.
 *
 * Writes take an exclusive lock on a sidecar `.lock` file and land via
 * temp-file + rename(), which is POSIX-atomic — an unlocked reader sees either
 * the old or the new complete file, never a torn write. Same discipline as
 * SubscriptionIndex, and the same trade: no fsync(), because losing the buffer
 * on a host crash costs at most one missed notification per URI and streams
 * re-read on their next tick.
 *
 * Reads take no lock. Everything this class does is best-effort by design: a
 * dropped notification is a missed refresh, and it must never be able to fail a
 * request that is only trying to save an object.
 */
final readonly class FileNotificationBus implements NotificationBusInterface
{
	private MessageFactory $messageFactory;

	/**
	 * @param string $busFile absolute path to the buffer file
	 * @param int    $backlog maximum entries retained; older ones are dropped
	 * @param int    $ttl     seconds an entry stays readable
	 */
	public function __construct(
		private string $busFile,
		private int $backlog = 256,
		private int $ttl = 120,
		private LoggerInterface $logger = new NullLogger(),
		?MessageFactory $messageFactory = null,
	) {
		$this->messageFactory = $messageFactory ?? MessageFactory::make();
	}

	// -------------------------------------------------------------------------
	// NotificationBusInterface
	// -------------------------------------------------------------------------

	public function publish(Notification $notification): void
	{
		try {
			$frame = json_encode($notification, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('Could not encode a notification for the bus.', ['exception' => $e]);

			return;
		}

		$this->mutate(function (array $state) use ($frame): array {
			$sequence = $state['cursor'];

			$state['entries'][] = ['seq' => $sequence, 'at' => time(), 'frame' => $frame];
			$state['cursor']    = $sequence + 1;

			// Trim to the ring size. Slicing on write is what keeps the file
			// bounded — a listen stream that goes away must not make the
			// backlog grow without end.
			if (count($state['entries']) > $this->backlog) {
				$state['entries'] = array_slice($state['entries'], -$this->backlog);
			}

			return $state;
		});
	}

	public function cursor(): int
	{
		return $this->read()['cursor'];
	}

	public function since(int $cursor): array
	{
		$state  = $this->read();
		$head   = $state['cursor'];
		$oldest = time() - $this->ttl;

		$found = [];

		foreach ($state['entries'] as $entry) {
			if ($entry['seq'] < $cursor || $entry['seq'] >= $head) {
				continue;
			}

			if ($entry['at'] < $oldest) {
				continue;
			}

			foreach ($this->decode($entry) as $notification) {
				$found[] = $notification;
			}
		}

		return [$found, $head];
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Decode one entry's frame into whatever notifications it carries.
	 *
	 * An entry that will not parse is dropped rather than failing the batch:
	 * the cursor still advances past it, so one bad frame cannot wedge a stream
	 * into re-reading it forever.
	 *
	 * @param array{seq:int,at:int,frame:mixed} $entry
	 *
	 * @return list<Notification>
	 */
	private function decode(array $entry): array
	{
		if (!\is_string($entry['frame'])) {
			return [];
		}

		try {
			$found = [];
			foreach ($this->messageFactory->create($entry['frame']) as $message) {
				if ($message instanceof Notification) {
					$found[] = $message;
				}
			}

			return $found;
		} catch (\Throwable $e) {
			$this->logger->warning('Dropped an unreadable notification from the bus.', [
				'sequence'  => $entry['seq'],
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * Read the buffer, normalising anything unexpected to an empty one.
	 *
	 * A missing file is the ordinary cold-start case. A corrupt one is treated
	 * the same way on purpose — this is a best-effort refresh channel, and
	 * throwing here would surface inside a listen stream or, worse, inside the
	 * object save that published to it.
	 *
	 * @return array{cursor:int,entries:list<array{seq:int,at:int,frame:mixed}>}
	 */
	private function read(): array
	{
		$empty = ['cursor' => 0, 'entries' => []];

		if (!is_file($this->busFile)) {
			return $empty;
		}

		$raw = @file_get_contents($this->busFile);
		if ($raw === false || $raw === '') {
			return $empty;
		}

		$data = json_decode($raw, true);
		if (!\is_array($data) || !\is_int($data['cursor'] ?? null) || !\is_array($data['entries'] ?? null)) {
			return $empty;
		}

		$entries = [];
		foreach ($data['entries'] as $entry) {
			if (\is_array($entry) && \is_int($entry['seq'] ?? null) && \is_int($entry['at'] ?? null)) {
				$entries[] = ['seq' => $entry['seq'], 'at' => $entry['at'], 'frame' => $entry['frame'] ?? null];
			}
		}

		return ['cursor' => $data['cursor'], 'entries' => $entries];
	}

	/**
	 * Read-modify-write the buffer under an exclusive lock.
	 *
	 * Every failure path is swallowed and logged. Publishing runs inside an
	 * object save; a full disk must cost a notification, not the write.
	 *
	 * @param callable(array{cursor:int,entries:list<array{seq:int,at:int,frame:mixed}>}): array{cursor:int,entries:list<array{seq:int,at:int,frame:mixed}>} $callback
	 */
	private function mutate(callable $callback): void
	{
		$directory = \dirname($this->busFile);
		if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
			$this->logger->warning('Could not create the notification bus directory.', ['dir' => $directory]);

			return;
		}

		// The lock lives beside the data file, not in it, so the lock FD stays
		// held across the rename that replaces the data file.
		$lock = @fopen($this->busFile . '.lock', 'c');
		if ($lock === false) {
			$this->logger->warning('Could not open the notification bus lock.', ['file' => $this->busFile]);

			return;
		}

		try {
			if (!flock($lock, LOCK_EX)) {
				return;
			}

			$state = $callback($this->read());
			$json  = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($json === false) {
				return;
			}

			$tmp = $this->busFile . '.tmp';
			if (@file_put_contents($tmp, $json) === false) {
				return;
			}

			if (!@rename($tmp, $this->busFile)) {
				@unlink($tmp);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not write to the notification bus.', ['exception' => $e]);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
