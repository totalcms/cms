<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
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
			$scheduledAt = self::scheduleToUtc($scheduledAt);
		} catch (\Exception) {
			return OperationResult::failure('Invalid schedule date: ' . $scheduledAt);
		}

		// A template goes to each object once. Leave out the objects that
		// already have it rather than queueing jobs that will only be skipped,
		// so the count reported back is the number of emails that will go
		// out. Test sends to an override address are never deduped.
		$delivered = $effectiveOverrideTo === null ? $this->bulkMailerRepository->fetchDeliveredObjectIds($mailerId) : [];

		$batchId  = uniqid('bulk_', true);
		$count    = 0;
		$excluded = 0;

		foreach ($objects as $object) {
			$objectId = (string)($object['id'] ?? '');
			if ($objectId === '') {
				continue;
			}

			if (isset($delivered[$objectId])) {
				$excluded++;

				continue;
			}

			$jobData = [
				'mailerId'   => $mailerId,
				'objectId'   => $objectId,
				'collection' => $collection,
				'batchId'    => $batchId,
				'overrideTo' => $effectiveOverrideTo,
			];

			$this->jobQueuer->queueEmail($jobData, $scheduledAt);
			$count++;
		}

		if ($count === 0 && $excluded > 0) {
			return OperationResult::failure(sprintf(
				'Nothing to send: all %d matching objects have already received this email. A template is sent to each object only once — duplicate it to send again.',
				$excluded,
			));
		}

		$this->bulkMailerRepository->recordBatch($batchId, $mailerId, $collection, $count, $excluded, $effectiveOverrideTo, $scheduledAt);

		$this->logger->info('Bulk send queued', [
			'mailerId'   => $mailerId,
			'batchId'    => $batchId,
			'count'      => $count,
			'excluded'   => $excluded,
			'collection' => $collection,
			'scheduled'  => $scheduledAt,
		]);

		$message = sprintf('Queued %d emails for sending', $count);
		if ($excluded > 0) {
			$message .= sprintf(' (%d left out: already received this email)', $excluded);
		}

		return OperationResult::success(
			$message,
			['batchId' => $batchId, 'count' => $count, 'excluded' => $excluded],
		);
	}

	/**
	 * The job queue compares scheduledAt against SQLite's CURRENT_TIMESTAMP,
	 * which is UTC `Y-m-d H:i:s`. The admin's datetime field posts the site's
	 * local time as `Y-m-d\TH:i`, which compared as a string never matched
	 * that shape: a send scheduled for later today waited for the next UTC
	 * day, and every schedule ignored the site's timezone.
	 */
	private static function scheduleToUtc(?string $scheduledAt): ?string
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
