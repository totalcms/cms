<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Data;

/**
 * BulkBatchSummaryData is one bulk send batch with its per-status counts.
 */
readonly class BulkBatchSummaryData
{
	/**
	 * @param int|null $queued Jobs queued for the batch; null for batches
	 *                         logged before batches were recorded
	 */
	public function __construct(
		public string $batchId,
		public string $collection,
		public ?int $queued,
		public int $excluded,
		public string $overrideTo,
		public string $scheduledAt,
		public string $startedAt,
		public string $lastSentAt,
		public int $sent,
		public int $failed,
		public int $skipped,
	) {
	}

	/**
	 * Build from a bulk_batches row and/or the batch's aggregated log row.
	 * At least one of the two is present.
	 *
	 * @param array<string,mixed>|null $batch
	 * @param array<string,mixed>|null $log
	 */
	public static function fromRows(?array $batch, ?array $log): self
	{
		return new self(
			batchId: (string)($batch['batchId'] ?? $log['batchId'] ?? ''),
			collection: (string)($batch['collection'] ?? $log['collection'] ?? ''),
			queued: $batch !== null ? intval($batch['queued'] ?? 0) : null,
			excluded: intval($batch['excluded'] ?? 0),
			overrideTo: (string)($batch['overrideTo'] ?? $log['sentTo'] ?? ''),
			scheduledAt: (string)($batch['scheduledAt'] ?? ''),
			startedAt: (string)($batch['createdAt'] ?? $log['firstAt'] ?? ''),
			lastSentAt: (string)($log['lastAt'] ?? ''),
			sent: intval($log['sent'] ?? 0),
			failed: intval($log['failed'] ?? 0),
			skipped: intval($log['skipped'] ?? 0),
		);
	}

	/**
	 * A test batch sent every email to an override address.
	 */
	public function isTest(): bool
	{
		return $this->overrideTo !== '';
	}

	/**
	 * Jobs queued but not yet processed; null when the queued total is unknown.
	 */
	public function pending(): ?int
	{
		if ($this->queued === null) {
			return null;
		}

		return max(0, $this->queued - $this->sent - $this->failed - $this->skipped);
	}
}
