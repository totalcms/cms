<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Data;

use TotalCMS\Domain\Auth\Data\Actor;

/**
 * One row of the activity log: what happened, who did it, and to what.
 *
 * The actor columns are flattened from {@see Actor::toArray()};
 * `initiator` is `kind:userId` of whoever started work the system carried out
 * (an automation run, a job), or '' when the actor acted directly.
 */
final readonly class ActivityEntry
{
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';

	/**
	 * One parameter per column of the activity table, all named and defaulted.
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<string,mixed> $detail
	 */
	public function __construct(
		public string $event,
		public string $level = self::LEVEL_INFO,
		public string $actorKind = '',
		public string $userId = '',
		public string $authCollection = '',
		public string $clientId = '',
		public string $apiKeyId = '',
		public string $initiator = '',
		public string $collection = '',
		public string $objectId = '',
		public string $summary = '',
		public array $detail = [],
		public int $createdAt = 0,
		public int $id = 0,
	) {
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		$detail = json_decode((string)($row['detail'] ?? ''), true);

		return new self(
			event: (string)$row['event'],
			level: (string)$row['level'],
			actorKind: (string)$row['actorKind'],
			userId: (string)$row['userId'],
			authCollection: (string)$row['authCollection'],
			clientId: (string)$row['clientId'],
			apiKeyId: (string)$row['apiKeyId'],
			initiator: (string)$row['initiator'],
			collection: (string)$row['collection'],
			objectId: (string)$row['objectId'],
			summary: (string)$row['summary'],
			detail: is_array($detail) ? $detail : [],
			createdAt: (int)$row['createdAt'],
			id: (int)$row['id'],
		);
	}

	/**
	 * Who did it, as one short label: the user, else the API key, else the
	 * actor kind ("system", "anonymous").
	 */
	public function actorLabel(): string
	{
		if ($this->userId !== '') {
			return $this->authCollection !== '' ? "{$this->authCollection}/{$this->userId}" : $this->userId;
		}
		if ($this->apiKeyId !== '') {
			return "API key {$this->apiKeyId}";
		}

		return $this->actorKind;
	}
}
