<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\IndexNow;

use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;

/**
 * The set of URLs waiting to be submitted, shared by every save between two
 * runs of the job queue.
 *
 * Without this, each save would carry its own job and its own request: fifty
 * edits before the next cron tick would be fifty POSTs, and a rate limit
 * would multiply them on retry. IndexNow takes up to 10,000 URLs in one
 * request, so saves append here and a single pending job drains the lot.
 *
 * It is a set — URLs are the keys — so a record saved five times is
 * submitted once. The value under each key is when it was first queued,
 * kept so the file answers "how long has this been waiting?" on its own.
 * Reads and writes go through AtomicJsonStore under its sidecar lock — two
 * requests appending at the same moment, or a save landing while the job is
 * taking the set, cannot lose a URL.
 */
readonly class IndexNowOutbox
{
	public const PATH = '.system/indexnow-outbox.json';

	/** The `collection` of the one pending job: the outbox is site-wide, not per collection. */
	public const JOB_COLLECTION = '*';

	public function __construct(
		private AtomicJsonStore $store,
	) {
	}

	/** @param list<string> $urls */
	public function add(array $urls): void
	{
		if ($urls === []) {
			return;
		}

		$this->store->mutate(self::PATH, static function (array $data) use ($urls): array {
			$set = is_array($data['urls'] ?? null) ? $data['urls'] : [];
			$now = date(\DateTimeInterface::ATOM);
			foreach ($urls as $url) {
				// First-queued time survives re-saves of the same record.
				$set[$url] ??= $now;
			}

			return ['urls' => $set];
		}, CorruptPolicy::TreatAsEmpty, lock: true);
	}

	/**
	 * Everything waiting, and the outbox emptied — one atomic step, so a
	 * save that lands during the take goes into the next batch, never lost.
	 *
	 * @return list<string>
	 */
	public function take(): array
	{
		$taken = [];
		$this->store->mutate(self::PATH, static function (array $data) use (&$taken): array {
			$taken = is_array($data['urls'] ?? null) ? array_keys($data['urls']) : [];

			return ['urls' => []];
		}, CorruptPolicy::TreatAsEmpty, lock: true);

		return array_values(array_filter($taken, is_string(...)));
	}

	/**
	 * Discard everything waiting. Called when the job queue is cleared, so
	 * "clear the queue" means what an operator expects: the submissions the
	 * pending job would have sent are gone too, not merely delayed until the
	 * next save queues a replacement job that drains them anyway.
	 */
	public function clear(): void
	{
		$this->store->mutate(self::PATH, static fn (array $data): array => ['urls' => []], CorruptPolicy::TreatAsEmpty, lock: true);
	}

	/**
	 * Put URLs back after a submission that should be retried, merging with
	 * whatever saves have added since.
	 *
	 * @param list<string> $urls
	 */
	public function restore(array $urls): void
	{
		$this->add($urls);
	}
}
