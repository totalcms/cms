<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Service;

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Support\OperationResult;

/**
 * Records email in the activity log: each send, whether it went out, who it
 * went to and what it was about, and each bulk send when it is queued.
 *
 * A bulk send is one row when queued, not one per recipient; its Send
 * History has those. The body of an email is never stored.
 */
readonly class MailActivity
{
	public const SENT        = 'mailer.sent';
	public const BULK_QUEUED = 'mailer.bulk.queued';

	/** @var list<string> */
	public const EVENTS = [self::SENT, self::BULK_QUEUED];

	private const MAILER_COLLECTION = 'mailer';

	public function __construct(
		private ActivityRecorder $recorder,
		private ActorContext $actors,
	) {
	}

	/**
	 * One email, sent or refused.
	 *
	 * @param array<string,mixed> $email    the message as EmailSender takes it; [] when refused before it was built
	 * @param string              $mailerId the mailer template it came from, '' for a built-in email
	 */
	public function sent(array $email, OperationResult $result, string $mailerId = ''): void
	{
		$subject = $this->str($email, 'subject');
		$summary = $result->success ? $subject : trim($subject . ': ' . ($result->error ?? $result->message), ' :');

		$this->recorder->record(self::SENT, $this->actors->current()->toArray(), [
			'level'      => $result->success ? ActivityEntry::LEVEL_INFO : ActivityEntry::LEVEL_WARNING,
			'collection' => $mailerId !== '' ? self::MAILER_COLLECTION : '',
			'objectId'   => $mailerId,
			'summary'    => $summary,
			'detail'     => array_filter([
				'to'    => $this->str($email, 'to'),
				'cc'    => $this->count($email, 'cc'),
				'bcc'   => $this->count($email, 'bcc'),
				'error' => $result->success ? '' : ($result->error ?? $result->message),
			]),
		]);
	}

	/** A bulk send queued for $count recipients, or for an override address when testing. */
	public function bulkQueued(string $mailerId, string $collection, int $count, ?string $overrideTo): void
	{
		$this->recorder->record(self::BULK_QUEUED, $this->actors->current()->toArray(), [
			'collection' => self::MAILER_COLLECTION,
			'objectId'   => $mailerId,
			'summary'    => sprintf('%d %s from %s', $count, $count === 1 ? 'email' : 'emails', $collection),
			'detail'     => array_filter(['recipients' => $collection, 'testTo' => (string)$overrideTo]),
		]);
	}

	/** @param array<string,mixed> $email */
	private function str(array $email, string $key): string
	{
		$value = $email[$key] ?? '';

		return is_scalar($value) ? (string)$value : '';
	}

	/** @param array<string,mixed> $email */
	private function count(array $email, string $key): int
	{
		$value = $email[$key] ?? [];

		return is_array($value) ? count(array_filter($value, static fn (mixed $address): bool => $address !== '')) : 0;
	}
}
