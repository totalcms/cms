<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Job;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Mailer\Service\BulkUnsubscribe;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Sends one queued Bulk Send message. JobRunner checks the hourly and daily
 * send limits before handing the job here.
 */
class BulkEmailJob
{
	private LoggerInterface $logger;

	public function __construct(
		private readonly EmailService $emailService,
		private readonly BulkMailerRepository $bulkMailerRepository,
		private readonly ObjectFetcher $objectFetcher,
		private readonly BulkUnsubscribe $unsubscribe,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JobRunner);
	}

	public function run(JobData $job): void
	{
		$data = json_decode($job->payload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$error = 'Invalid JSON payload: ' . json_last_error_msg();
			$this->logger->error($error, $job->toArray());

			return;
		}

		$mailerId   = (string)($data['mailerId'] ?? '');
		$objectId   = (string)($data['objectId'] ?? '');
		$collection = (string)($data['collection'] ?? '');
		$batchId    = (string)($data['batchId'] ?? '');
		$overrideTo = isset($data['overrideTo']) ? (string)$data['overrideTo'] : null;
		$ids        = ['batchId' => $batchId, 'mailerId' => $mailerId, 'collection' => $collection, 'objectId' => $objectId];

		// Skip if already delivered. Test sends to an override address are
		// neither deduped nor counted as a delivery, so a batch can be proofed
		// any number of times before the real send.
		if ($overrideTo === null && $this->bulkMailerRepository->hasBeenSent($mailerId, $objectId)) {
			$this->logger->info('Skipping already-sent email', [
				'mailerId'   => $mailerId,
				'objectId'   => $objectId,
				'collection' => $collection,
			]);

			$this->record($ids, ['status' => 'skipped']);

			return;
		}

		// Apply send delay for throttling
		$sendDelay = intval($this->config->smtp['sendDelay'] ?? 0);
		if ($sendDelay > 0) {
			usleep($sendDelay * 1000);
		}

		// Fetch object data. An object deleted since the send was queued is
		// logged as failed, so Send History does not show it pending forever.
		try {
			$objectData = $this->objectFetcher->fetchObject($collection, $objectId)->toArray();
		} catch (\Throwable $e) {
			$this->record($ids, ['sentTo' => $overrideTo ?? '', 'status' => 'failed', 'error' => $e->getMessage()]);

			throw $e;
		}

		// A recipient who unsubscribed after the send was queued. A test copy
		// goes to the tester, so it is sent regardless.
		if ($overrideTo === null && $this->unsubscribe->isUnsubscribed($collection, $objectData)) {
			$this->record($ids, ['status' => 'skipped', 'error' => 'Recipient has unsubscribed']);

			return;
		}

		[$vars, $headers] = $this->unsubscribeParts($collection, $objectId, $overrideTo);

		// Send email
		$result = $this->emailService->sendEmail($mailerId, $objectData, $overrideTo, [], false, $vars, $headers);

		// Log result
		$this->record($ids, ['sentTo' => $overrideTo ?? '', 'status' => $result->success ? 'sent' : 'failed', 'error' => $result->error]);

		if (!$result->success) {
			throw new \RuntimeException('Email send failed: ' . $result->message);
		}

		$this->logger->info('Bulk email sent', [
			'mailerId'   => $mailerId,
			'objectId'   => $objectId,
			'collection' => $collection,
			'batchId'    => $batchId,
		]);
	}

	/**
	 * The `unsubscribeUrl` Twig variable and the one-click headers. A test
	 * copy gets a link that changes nothing and no headers, so the tester
	 * cannot unsubscribe the recipient they are proofing.
	 *
	 * @return array{array<string,string>, array<string,string>}
	 */
	private function unsubscribeParts(string $collection, string $objectId, ?string $overrideTo): array
	{
		if ($overrideTo !== null) {
			return $this->unsubscribe->property($collection) === null ? [[], []] : [['unsubscribeUrl' => $this->unsubscribe->previewUrl()], []];
		}

		$headers = $this->unsubscribe->headers($collection, $objectId);

		return $headers === [] ? [[], []] : [['unsubscribeUrl' => $this->unsubscribe->url($collection, $objectId)], $headers];
	}

	/**
	 * One Send History row for this job.
	 *
	 * @param array<string,string> $ids batchId, mailerId, collection, objectId
	 * @param array<string,mixed>  $row
	 */
	private function record(array $ids, array $row): void
	{
		$this->bulkMailerRepository->log($ids + $row);
	}
}
