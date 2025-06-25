<?php

namespace TotalCMS\Domain\JobQueue\Data;

class JobData
{
	public string $id;
	public string $type;
	public string $payload;
	public string $status;
	public string $collection;
	public int $attempts;
	public string $createdAt;
	public string $updatedAt;
	public string $lastError;

	public const STATUS_PENDING     = 'pending';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_FAILED      = 'failed';
	public const STATUS_LIST        = [
		self::STATUS_PENDING,
		self::STATUS_IN_PROGRESS,
		self::STATUS_FAILED,
	];

	public const TYPE_IMPORT  = 'import';
	public const TYPE_UPDATE  = 'update';
	public const TYPE_EXPORT  = 'export';
	public const TYPE_REBUILD = 'rebuild';
	public const TYPE_LIST    = [
		self::TYPE_IMPORT,
		self::TYPE_EXPORT,
		self::TYPE_REBUILD,
		self::TYPE_UPDATE,
	];

	/** @return array<string,string|int> */
	public function toArray(): array
	{
		return [
			'id'         => $this->id,
			'type'       => $this->type,
			'payload'    => $this->payload,
			'status'     => $this->status,
			'collection' => $this->collection,
			'attempts'   => $this->attempts,
			'createdAt'  => $this->createdAt,
			'updatedAt'  => $this->updatedAt,
			'lastError'  => $this->lastError,
		];
	}

	/** @param array<string,string> $data */
	public static function fromArray(array $data): self
	{
		$instance             = new self();
		$instance->id         = $data['id'] ?? '';
		$instance->type       = $data['type'] ?? '';
		$instance->payload    = $data['payload'] ?? '';
		$instance->status     = $data['status'] ?? '';
		$instance->collection = $data['collection'] ?? '';
		$instance->attempts   = intval($data['attempts'] ?? 0);
		$instance->createdAt  = $data['createdAt'] ?? '';
		$instance->updatedAt  = $data['updatedAt'] ?? '';
		$instance->lastError  = $data['lastError'] ?? '';

		return $instance;
	}
}
