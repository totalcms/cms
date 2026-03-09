<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Data;

/**
 * BulkMailLogData represents a single send log entry for bulk mailer.
 */
readonly class BulkMailLogData
{
	public function __construct(
		public int $id,
		public string $batchId,
		public string $mailerId,
		public string $collection,
		public string $objectId,
		public string $sentTo,
		public string $status,
		public string $error,
		public string $scheduledAt,
		public string $sentAt,
	) {
	}

	/**
	 * Create from array.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			id: intval($data['id'] ?? 0),
			batchId: (string)($data['batchId'] ?? ''),
			mailerId: (string)($data['mailerId'] ?? ''),
			collection: (string)($data['collection'] ?? ''),
			objectId: (string)($data['objectId'] ?? ''),
			sentTo: (string)($data['sentTo'] ?? ''),
			status: (string)($data['status'] ?? 'sent'),
			error: (string)($data['error'] ?? ''),
			scheduledAt: (string)($data['scheduledAt'] ?? ''),
			sentAt: (string)($data['sentAt'] ?? ''),
		);
	}

	/**
	 * Convert to array.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'id'          => $this->id,
			'batchId'     => $this->batchId,
			'mailerId'    => $this->mailerId,
			'collection'  => $this->collection,
			'objectId'    => $this->objectId,
			'sentTo'      => $this->sentTo,
			'status'      => $this->status,
			'error'       => $this->error,
			'scheduledAt' => $this->scheduledAt,
			'sentAt'      => $this->sentAt,
		];
	}
}
