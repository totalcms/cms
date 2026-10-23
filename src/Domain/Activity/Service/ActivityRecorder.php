<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;
use TotalCMS\Support\Config;

/**
 * Writes rows to the activity log, applying the Settings → Activity switches
 * and retention.
 *
 * Recording never breaks the work being recorded: a failed write (locked or
 * unwritable store) is logged and dropped.
 */
class ActivityRecorder
{
	public const DEFAULT_RETENTION_DAYS = 90;

	public function __construct(
		private readonly ActivityRepository $repository,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function enabled(): bool
	{
		return ($this->config->activity['enable'] ?? true) !== false;
	}

	/** Whether tool calls from callers who are not signed in are recorded. */
	public function recordsAnonymousMcp(): bool
	{
		return ($this->config->activity['mcpAnonymous'] ?? false) === true;
	}

	/**
	 * @param array<string,mixed> $actor  {@see \TotalCMS\Domain\Auth\Data\Actor::toArray()}
	 * @param array{level?: string, userId?: string, authCollection?: string, collection?: string, objectId?: string, summary?: string, detail?: array<string,mixed>} $fields
	 */
	public function record(string $event, array $actor, array $fields = []): void
	{
		if (!$this->enabled()) {
			return;
		}

		$initiator = is_array($actor['initiator'] ?? null) ? $actor['initiator'] : null;

		$entry = new ActivityEntry(
			event: $event,
			level: $fields['level'] ?? ActivityEntry::LEVEL_INFO,
			actorKind: (string)($actor['kind'] ?? ''),
			userId: $fields['userId'] ?? (string)($actor['userId'] ?? ''),
			authCollection: $fields['authCollection'] ?? (string)($actor['authCollection'] ?? ''),
			clientId: (string)($actor['clientId'] ?? ''),
			apiKeyId: (string)($actor['apiKeyId'] ?? ''),
			initiator: $initiator === null ? '' : trim((string)($initiator['kind'] ?? '') . ':' . (string)($initiator['userId'] ?? ''), ':'),
			collection: $fields['collection'] ?? '',
			objectId: $fields['objectId'] ?? '',
			summary: mb_substr($fields['summary'] ?? '', 0, 500),
			detail: $fields['detail'] ?? [],
			createdAt: time(),
		);

		try {
			$this->repository->record($entry);
			$this->repository->pruneIfDue($this->retentionDays(), time());
		} catch (\Throwable $e) {
			$this->logger->warning("Activity not recorded ({$event}): {$e->getMessage()}");
		}
	}

	public function retentionDays(): int
	{
		$days = (int)($this->config->activity['retentionDays'] ?? self::DEFAULT_RETENTION_DAYS);

		return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
	}
}
