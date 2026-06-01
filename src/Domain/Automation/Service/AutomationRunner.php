<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Automation\Data\AutomationContext;
use TotalCMS\Domain\Automation\Data\RunRecord;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Executes an automation handler with an AutomationContext, captures the run as
 * a RunRecord on disk, and tracks consecutive-failure counts. Environment-aware
 * notification and auto-disable are layered on in Plan 4.
 */
final class AutomationRunner
{
	private LoggerInterface $logger;

	public function __construct(
		private readonly AutomationLoader $loader,
		private readonly AutomationStateStore $state,
		private readonly StorageAdapterInterface $filesystem,
		private readonly IndexReader $indexReader,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectSaver $objectSaver,
		private readonly ObjectUpdater $objectUpdater,
		private readonly ObjectRemover $objectRemover,
		private readonly EmailService $mailer,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('automations.log')->createLogger('automations');
	}

	/**
	 * @param array<string,mixed> $trigger
	 * @param array<string,mixed> $args
	 * @param array<string,mixed>|null $event
	 */
	public function run(string $slug, array $trigger, array $args, ?ServerRequestInterface $request = null, ?array $event = null): RunRecord
	{
		$runId     = gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(6));
		$startedAt = gmdate('c');
		$start     = hrtime(true);

		$ctx = new AutomationContext(
			indexReader: $this->indexReader,
			objectFetcher: $this->objectFetcher,
			objectSaver: $this->objectSaver,
			objectUpdater: $this->objectUpdater,
			objectRemover: $this->objectRemover,
			mailer: $this->mailer,
			config: $this->config,
			logger: $this->logger,
			trigger: $trigger,
			args: $args,
			request: $request,
			event: $event,
		);

		$status    = 'success';
		$return    = null;
		$exception = null;

		try {
			$fn     = $this->loader->handler($slug);
			$return = $fn($ctx);
			$this->state->resetFailures($slug);
		} catch (\Throwable $e) {
			$status    = 'failed';
			$exception = $e->getMessage() . "\n" . $e->getTraceAsString();
			$this->state->incrementFailures($slug);
			$this->logger->error("Automation '{$slug}' failed: {$e->getMessage()}", ['exception' => $e]);
		}

		$record = new RunRecord(
			runId      : $runId,
			automation : $slug,
			trigger    : $trigger,
			status     : $status,
			startedAt  : $startedAt,
			finishedAt : gmdate('c'),
			durationMs : (int)((hrtime(true) - $start) / 1_000_000),
			return     : $return,
			exception  : $exception,
		);

		$this->persistRun($slug, $record);

		return $record;
	}

	private function persistRun(string $slug, RunRecord $record): void
	{
		$dir = '.system/automations/' . $slug . '/runs';
		$this->filesystem->write(
			$dir . '/' . $record->runId . '.json',
			(string)json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);
		$this->prune($dir);
	}

	private function prune(string $dir): void
	{
		$limit = (int)($this->config->automations['runHistoryLimit'] ?? 100);
		$files = $this->filesystem->listFiles($dir);

		if (count($files) <= $limit) {
			return;
		}

		// Run ids are time-prefixed, so a lexical sort is chronological.
		sort($files);
		foreach (array_slice($files, 0, count($files) - $limit) as $old) {
			$this->filesystem->delete($old);
		}
	}
}
