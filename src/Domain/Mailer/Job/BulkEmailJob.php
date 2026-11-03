<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Job;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Mailer\Service\BulkUnsubscribe;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\MailHeaders;
use TotalCMS\Domain\Mailer\Service\RenderedBulkHooks;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

/**
 * Sends one queued Bulk Send message: from a mailer template, or from a
 * rendered send's stored Twig source (`kind: rendered`). JobRunner checks
 * the hourly and daily send limits before handing the job here.
 */
class BulkEmailJob
{
	public const KIND_RENDERED = 'rendered';

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly EmailService $emailService,
		private readonly BulkMailerRepository $bulkMailerRepository,
		private readonly ObjectFetcher $objectFetcher,
		private readonly BulkUnsubscribe $unsubscribe,
		private readonly RenderedBulkHooks $hooks,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JobRunner);
	}

	public function run(JobData $job): void
	{
		$data = json_decode($job->payload, true);
		if (!is_array($data)) {
			$this->logger->error('Invalid JSON payload: ' . json_last_error_msg(), $job->toArray());

			return;
		}

		$ids = [
			'batchId'    => (string)($data['batchId'] ?? ''),
			'mailerId'   => (string)($data['mailerId'] ?? ''),
			'collection' => (string)($data['collection'] ?? ''),
			'objectId'   => (string)($data['objectId'] ?? ''),
		];
		$overrideTo = isset($data['overrideTo']) ? (string)$data['overrideTo'] : null;

		$message = null;
		if (($data['kind'] ?? '') === self::KIND_RENDERED) {
			$stored = $this->bulkMailerRepository->fetchMessage($ids['batchId']);
			if ($stored === null) {
				// A retry cannot bring the message back, so the job ends here.
				$this->record($ids, ['status' => 'failed', 'error' => 'The message for this batch is missing']);

				return;
			}
			$message = RenderedBulkMessage::fromArray($stored);
		}

		$object = $this->recipient($ids, $overrideTo, $message);
		if ($object === null) {
			return;
		}

		$result = $this->send($ids, $object, $overrideTo, $message);

		$this->record($ids, ['sentTo' => $overrideTo ?? '', 'status' => $result->success ? 'sent' : 'failed', 'error' => $result->error]);

		if (!$result->success) {
			throw new \RuntimeException('Email send failed: ' . $result->message);
		}

		$this->logger->info('Bulk email sent', $ids);
	}

	/**
	 * The recipient object, or null when this job sends nothing: the object
	 * already received the source, unsubscribed, or the caller's filter
	 * declines it. Test sends to an override address are neither deduped nor
	 * filtered, so a batch can be proofed any number of times.
	 *
	 * @param array<string,string> $ids
	 *
	 * @return array<string,mixed>|null
	 */
	private function recipient(array $ids, ?string $overrideTo, ?RenderedBulkMessage $message): ?array
	{
		if ($overrideTo === null && $this->bulkMailerRepository->hasBeenSent($ids['mailerId'], $ids['objectId'])) {
			$this->logger->info('Skipping already-sent email', $ids);
			$this->record($ids, ['status' => 'skipped']);

			return null;
		}

		$sendDelay = intval($this->config->smtp['sendDelay'] ?? 0);
		if ($sendDelay > 0) {
			usleep($sendDelay * 1000);
		}

		// An object deleted since the send was queued is logged as failed, so
		// Send History does not show it pending forever.
		try {
			$object = $this->objectFetcher->fetchObject($ids['collection'], $ids['objectId'])->toArray();
		} catch (\Throwable $e) {
			$this->record($ids, ['sentTo' => $overrideTo ?? '', 'status' => 'failed', 'error' => $e->getMessage()]);

			throw $e;
		}

		$declined = match (true) {
			$overrideTo !== null                                                                     => null,
			$this->unsubscribe->isUnsubscribed($ids['collection'], $object)                          => 'Recipient has unsubscribed',
			$message instanceof RenderedBulkMessage && !$this->hooks->accepts($message, $object)     => 'Recipient filter declined',
			default                                                                                  => null,
		};
		if ($declined !== null) {
			$this->record($ids, ['status' => 'skipped', 'error' => $declined]);

			return null;
		}

		return $object;
	}

	/**
	 * @param array<string,string> $ids
	 * @param array<string,mixed>  $object
	 */
	private function send(array $ids, array $object, ?string $overrideTo, ?RenderedBulkMessage $message): OperationResult
	{
		[$vars, $headers] = $this->unsubscribeParts($ids['collection'], $ids['objectId'], $overrideTo);

		if (!$message instanceof RenderedBulkMessage) {
			return $this->emailService->sendEmail($ids['mailerId'], $object, $overrideTo, [], false, $vars, $headers);
		}

		$own = $this->hooks->variables($message, $object);

		return $this->emailService->sendRendered(
			$message,
			array_merge(['contact' => $object], $vars, $own['vars']),
			$overrideTo,
			MailHeaders::merge($headers, $own['headers']),
		);
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
