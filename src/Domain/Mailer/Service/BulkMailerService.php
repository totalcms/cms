<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Activity\Service\MailActivity;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Job\BulkEmailJob;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;

/**
 * BulkMailerService orchestrates bulk email sending via the job queue.
 */
readonly class BulkMailerService
{
	private LoggerInterface $logger;

	public function __construct(
		private MailerFetcher $mailerFetcher,
		private IndexFilter $indexFilter,
		private ObjectFetcher $objectFetcher,
		private JobQueuer $jobQueuer,
		private EditionFeatureService $editionFeatures,
		private TwigEngine $twigEngine,
		private BulkMailerRepository $bulkMailerRepository,
		private MailActivity $activity,
		private BulkUnsubscribe $unsubscribe,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::BulkMailer);
	}

	/**
	 * Queue a bulk send for all matching objects in a collection.
	 *
	 * @param list<string>|null $objectIds Specific object IDs to send to (overrides filters)
	 */
	public function queueBulkSend(string $mailerId, string $collection, string $include = '', string $exclude = '', ?string $scheduledAt = null, ?string $overrideTo = null, ?array $objectIds = null): OperationResult
	{
		if (!$this->editionFeatures->can(EditionFeature::BULK_MAILER)) {
			return OperationResult::failure('Bulk Mailer requires the Pro edition');
		}

		try {
			$mailer = $this->mailerFetcher->fetchMailer($mailerId);
		} catch (\Exception $e) {
			return OperationResult::failure('Mailer template not found: ' . $e->getMessage());
		}

		if (!$mailer->active) {
			return OperationResult::failure('Email template is not active');
		}

		if ($collection === '') {
			return OperationResult::failure('Bulk collection is required');
		}

		// Use specific object IDs if provided, otherwise apply filters
		if ($objectIds !== null && $objectIds !== []) {
			$objects = array_map(static fn (string $oid): array => ['id' => $oid], $objectIds);
		} else {
			$filterOptions = [];
			if ($include !== '') {
				$filterOptions['include'] = $include;
			}
			if ($exclude !== '') {
				$filterOptions['exclude'] = $exclude;
			}

			$objects = $this->indexFilter->fetchFilteredIndex($collection, $filterOptions);
		}

		if ($objects === []) {
			return OperationResult::failure('No matching objects found in collection "' . $collection . '"');
		}

		$effectiveOverrideTo = ($overrideTo !== null && $overrideTo !== '') ? $overrideTo : null;

		try {
			$scheduledAt = $this->scheduleToUtc($scheduledAt);
		} catch (\Exception) {
			return OperationResult::failure('Invalid schedule date: ' . $scheduledAt);
		}

		$result = $this->enqueue($mailerId, $collection, $objects, $scheduledAt, $effectiveOverrideTo);
		if ($result->success) {
			$this->activity->bulkQueued($mailerId, $collection, intval($result->data['count'] ?? 0), $effectiveOverrideTo);
		}

		return $result;
	}

	/**
	 * Queue a bulk send whose Twig source the caller supplies instead of a
	 * mailer template (BusinessKit campaigns). The message is stored once
	 * for the batch and rendered for each recipient when its job runs; see
	 * RenderedBulkMessage. Dedupe and Send History use its sourceId.
	 *
	 * @param list<string> $objectIds the recipients in $collection
	 * @param string|null  $scheduledAt site-local `Y-m-d H:i` (or any date PHP parses); null sends now
	 * @param string|null  $overrideTo send every message here instead, as a test
	 */
	public function queueRenderedSend(RenderedBulkMessage $message, string $collection, array $objectIds, ?string $scheduledAt = null, ?string $overrideTo = null): OperationResult
	{
		if (!$this->editionFeatures->can(EditionFeature::BULK_MAILER)) {
			return OperationResult::failure('Bulk Mailer requires the Pro edition');
		}

		$problem = $message->problem();
		if ($problem !== null) {
			return OperationResult::failure('Invalid message: ' . $problem);
		}

		$ids = array_values(array_unique(array_filter(array_map(trim(...), $objectIds), static fn (string $id): bool => $id !== '')));
		if ($collection === '' || $ids === []) {
			return OperationResult::failure('A collection and at least one object are required');
		}

		try {
			$scheduledAt = $this->scheduleToUtc($scheduledAt);
		} catch (\Exception) {
			return OperationResult::failure('Invalid schedule date: ' . $scheduledAt);
		}

		$objects = array_map(static fn (string $id): array => ['id' => $id], $ids);

		return $this->enqueue($message->sourceId, $collection, $objects, $scheduledAt, ($overrideTo ?? '') !== '' ? $overrideTo : null, $message);
	}

	/**
	 * Cancel the jobs of a batch that have not run yet: a scheduled send
	 * called off, or the rest of one in progress. Emails already sent stay
	 * sent; Send History shows the batch as cancelled.
	 *
	 * Give $mailerId when the request names the mailer it came from (the
	 * Send History button): a batch of any other mailer is then refused.
	 */
	public function cancelBatch(string $batchId, ?string $mailerId = null): OperationResult
	{
		if ($mailerId !== null && $this->bulkMailerRepository->fetchBatchMailerId($batchId) !== $mailerId) {
			return OperationResult::failure('Batch not found for this email');
		}

		$cancelled = $this->jobQueuer->cancelEmailBatch($batchId);
		if ($cancelled === 0) {
			return OperationResult::failure('Nothing to cancel: the batch has no emails waiting to be sent');
		}

		$this->bulkMailerRepository->recordCancelled($batchId, $cancelled);
		$this->logger->info('Bulk send cancelled', ['batchId' => $batchId, 'cancelled' => $cancelled]);

		return OperationResult::success(sprintf('Cancelled %d emails', $cancelled), ['batchId' => $batchId, 'cancelled' => $cancelled]);
	}

	/**
	 * Queue one job per recipient. Each source (a template id, or a rendered
	 * send's sourceId) goes to each object once, so objects that already have
	 * it are left out rather than queued only to be skipped; the count reported
	 * back is the number of emails that will go out. Test sends to an override
	 * address are never deduped.
	 *
	 * @param array<int,array<string,mixed>> $objects
	 */
	private function enqueue(string $sourceId, string $collection, array $objects, ?string $scheduledAt, ?string $overrideTo, ?RenderedBulkMessage $message = null): OperationResult
	{
		$delivered = $overrideTo === null ? $this->bulkMailerRepository->fetchDeliveredObjectIds($sourceId) : [];

		[$recipients, $excluded, $unsubscribed] = $this->recipients($objects, $collection, $delivered, $overrideTo === null);

		if ($recipients === []) {
			$hint = $message === null ? ' A template is sent to each object only once — duplicate it to send again.' : '';

			return OperationResult::failure($unsubscribed === 0
				? sprintf('Nothing to send: all %d matching objects have already received this email.', $excluded) . $hint
				: sprintf('Nothing to send: of %d matching objects, %d have unsubscribed and %d already received this email.', $excluded + $unsubscribed, $unsubscribed, $excluded));
		}

		$batchId = uniqid('bulk_', true);
		$payload = ['mailerId' => $sourceId, 'collection' => $collection, 'batchId' => $batchId, 'overrideTo' => $overrideTo];
		if ($message !== null) {
			// Stored before the first job is queued, so no job runs without it.
			$this->bulkMailerRepository->saveMessage($batchId, $message->toArray());
			$payload['kind'] = BulkEmailJob::KIND_RENDERED;
		}

		foreach ($recipients as $objectId) {
			$this->jobQueuer->queueEmail(['objectId' => $objectId] + $payload, $scheduledAt);
		}
		$count = count($recipients);

		$this->bulkMailerRepository->recordBatch($batchId, $sourceId, $collection, $count, $excluded + $unsubscribed, $overrideTo, $scheduledAt);

		$this->logger->info('Bulk send queued', [
			'mailerId'     => $sourceId,
			'batchId'      => $batchId,
			'count'        => $count,
			'excluded'     => $excluded,
			'unsubscribed' => $unsubscribed,
			'collection'   => $collection,
			'scheduled'    => $scheduledAt,
		]);

		return OperationResult::success(
			self::queuedMessage($count, $excluded, $unsubscribed),
			['batchId' => $batchId, 'count' => $count, 'excluded' => $excluded, 'unsubscribed' => $unsubscribed],
		);
	}

	/**
	 * The object ids to queue, leaving out the ones already delivered and,
	 * for a real send, the ones that unsubscribed. The unsubscribe check sees
	 * the property only when the index carries it; the job checks the stored
	 * object again at send time either way.
	 *
	 * @param array<int,array<string,mixed>> $objects
	 * @param array<string,mixed>           $delivered object ids already sent, as keys
	 *
	 * @return array{list<string>, int, int} ids, already delivered, unsubscribed
	 */
	private function recipients(array $objects, string $collection, array $delivered, bool $realSend): array
	{
		$ids          = [];
		$excluded     = 0;
		$unsubscribed = 0;

		foreach ($objects as $object) {
			$objectId = (string)($object['id'] ?? '');
			if ($objectId === '') {
				continue;
			}

			if (isset($delivered[$objectId])) {
				$excluded++;
			} elseif ($realSend && $this->unsubscribe->isUnsubscribed($collection, $object)) {
				$unsubscribed++;
			} else {
				$ids[] = $objectId;
			}
		}

		return [$ids, $excluded, $unsubscribed];
	}

	private static function queuedMessage(int $count, int $excluded, int $unsubscribed): string
	{
		$message = sprintf('Queued %d emails for sending', $count);
		if ($excluded > 0) {
			$message .= sprintf(' (%d left out: already received this email)', $excluded);
		}
		if ($unsubscribed > 0) {
			$message .= sprintf(' (%d unsubscribed)', $unsubscribed);
		}

		return $message;
	}

	/**
	 * The job queue compares scheduledAt against SQLite's CURRENT_TIMESTAMP,
	 * which is UTC `Y-m-d H:i:s`. The admin's datetime field posts the site's
	 * local time as `Y-m-d\TH:i`, which compared as a string never matched
	 * that shape: a send scheduled for later today waited for the next UTC
	 * day, and every schedule ignored the site's timezone.
	 */
	private function scheduleToUtc(?string $scheduledAt): ?string
	{
		if ($scheduledAt === null || trim($scheduledAt) === '') {
			return null;
		}

		$local = new \DateTimeImmutable($scheduledAt, new \DateTimeZone(date_default_timezone_get()));

		return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/**
	 * Preview a bulk email for a specific object.
	 */
	public function previewEmail(string $mailerId, string $objectId, string $collection): OperationResult
	{
		try {
			$mailer = $this->mailerFetcher->fetchMailer($mailerId);
		} catch (\Exception $e) {
			return OperationResult::failure('Mailer template not found: ' . $e->getMessage());
		}

		try {
			$object   = $this->objectFetcher->fetchObject($collection, $objectId);
			$twigData = ['data' => $object->properties->all()];
			if ($this->unsubscribe->property($collection) !== null) {
				$twigData['unsubscribeUrl'] = $this->unsubscribe->previewUrl();
			}

			$html    = $this->twigEngine->renderString($mailer->bodyHtml, $twigData);
			$subject = $this->twigEngine->renderString($mailer->subject, $twigData);
			$to      = $this->twigEngine->renderString($mailer->to, $twigData);

			return OperationResult::success('', [
				'html'    => $html,
				'subject' => $subject,
				'to'      => $to,
			]);
		} catch (\Exception $e) {
			return OperationResult::failure('Preview error: ' . $e->getMessage());
		}
	}
}
