<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;

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
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('bulk-mailer.log')->createLogger('bulk-mailer');
	}

	/**
	 * Queue a bulk send for all matching objects in a collection.
	 *
	 * @param list<string>|null $objectIds Specific object IDs to send to (overrides filters)
	 *
	 * @return array{success:bool,batchId?:string,count?:int,message:string}
	 */
	public function queueBulkSend(string $mailerId, string $collection, string $include = '', string $exclude = '', ?string $scheduledAt = null, ?string $overrideTo = null, ?array $objectIds = null): array
	{
		if (!$this->editionFeatures->can(EditionFeature::BULK_MAILER)) {
			return [
				'success' => false,
				'message' => 'Bulk Mailer requires the Pro edition',
			];
		}

		try {
			$mailer = $this->mailerFetcher->fetchMailer($mailerId);
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Mailer template not found: ' . $e->getMessage(),
			];
		}

		if (!$mailer->active) {
			return [
				'success' => false,
				'message' => 'Email template is not active',
			];
		}

		if ($collection === '') {
			return [
				'success' => false,
				'message' => 'Bulk collection is required',
			];
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
			return [
				'success' => false,
				'message' => 'No matching objects found in collection "' . $collection . '"',
			];
		}

		$effectiveOverrideTo = ($overrideTo !== null && $overrideTo !== '') ? $overrideTo : null;

		$batchId = uniqid('bulk_', true);
		$count   = 0;

		foreach ($objects as $object) {
			$objectId = (string)($object['id'] ?? '');
			if ($objectId === '') {
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

		$this->logger->info('Bulk send queued', [
			'mailerId'   => $mailerId,
			'batchId'    => $batchId,
			'count'      => $count,
			'collection' => $collection,
			'scheduled'  => $scheduledAt,
		]);

		return [
			'success' => true,
			'batchId' => $batchId,
			'count'   => $count,
			'message' => sprintf('Queued %d emails for sending', $count),
		];
	}

	/**
	 * Preview a bulk email for a specific object.
	 *
	 * @return array{success:bool,html?:string,subject?:string,to?:string,message?:string}
	 */
	public function previewEmail(string $mailerId, string $objectId, string $collection): array
	{
		try {
			$mailer = $this->mailerFetcher->fetchMailer($mailerId);
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Mailer template not found: ' . $e->getMessage(),
			];
		}

		try {
			$object   = $this->objectFetcher->fetchObject($collection, $objectId);
			$twigData = ['data' => $object->properties->all()];

			$html    = $this->twigEngine->renderString($mailer->bodyHtml, $twigData);
			$subject = $this->twigEngine->renderString($mailer->subject, $twigData);
			$to      = $this->twigEngine->renderString($mailer->to, $twigData);

			return [
				'success' => true,
				'html'    => $html,
				'subject' => $subject,
				'to'      => $to,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Preview error: ' . $e->getMessage(),
			];
		}
	}
}
