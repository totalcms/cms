<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\IndexNow;

use TotalCMS\Domain\JobQueue\Data\JobData;

/**
 * Drains the outbox: everything queued since the last run goes out in as
 * few requests as the protocol allows (10,000 URLs each). The job carries
 * no payload of its own — the outbox is the payload — so however many saves
 * queued it, one run sends one batch.
 *
 * A retryable failure (rate limit, server error, transport) puts the unsent
 * URLs back and throws, so JobRunner re-enqueues per its standard policy
 * and the next run picks them up together with anything newer. A rejection
 * (422) is final and is logged by the submitter; those URLs are dropped.
 */
readonly class IndexNowJob
{
	public function __construct(
		private IndexNowSubmitter $submitter,
		private IndexNowOutbox $outbox,
	) {
	}

	public function run(JobData $job): void
	{
		$urls = $this->outbox->take();
		if ($urls === []) {
			return;
		}

		$batches = array_chunk($urls, IndexNowSubmitter::MAX_URLS);
		foreach ($batches as $i => $batch) {
			try {
				$this->submitter->submit($batch);
			} catch (\Throwable $e) {
				// This batch and every later one are unsent; give them back.
				$this->outbox->restore(array_merge(...array_slice($batches, $i)));

				throw $e;
			}
		}
	}
}
