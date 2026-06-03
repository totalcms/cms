<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Automation\Data\RunRecord;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Executes an automation handler with an AutomationContext, captures the run as
 * a RunRecord on disk, and tracks consecutive-failure counts. Environment-aware
 * notification and auto-disable are layered on in Plan 4.
 */
final readonly class AutomationRunner
{
	private LoggerInterface $logger;

	public function __construct(
		private AutomationLoader $loader,
		private AutomationStateStore $state,
		private StorageAdapterInterface $filesystem,
		private AutomationContextFactory $contextFactory,
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private EmailService $mailer,
		private Config $config,
		private AutomationGuard $guard,
		private AutomationActivityLogger $activity,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('automations.log')->createLogger('automations');
	}

	/**
	 * @param array<string,mixed> $trigger
	 * @param array<string,mixed> $args
	 * @param array<string,mixed>|null $event
	 */
	public function run(string $id, array $trigger, array $args, ?ServerRequestInterface $request = null, ?array $event = null): RunRecord
	{
		$runId     = gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(6));
		$startedAt = gmdate('c');
		$start     = hrtime(true);

		$ctx = $this->contextFactory->create($trigger, $args, $request, $event);

		$triggerType = (string)($trigger['type'] ?? '');
		$this->activity->runStarted($id, $triggerType);

		$status    = 'success';
		$return    = null;
		$exception = null;
		$throwable = null;

		try {
			$fn     = $this->loader->handler($id);
			$return = $fn($ctx);
		} catch (\Throwable $e) {
			$status    = 'failed';
			$exception = $e->getMessage() . "\n" . $e->getTraceAsString();
			$throwable = $e;
			$this->logger->error("Automation '{$id}' failed: {$e->getMessage()}", ['exception' => $e]);
		}

		$durationMs = (int)((hrtime(true) - $start) / 1_000_000);

		$record = new RunRecord(
			runId      : $runId,
			automation : $id,
			trigger    : $trigger,
			status     : $status,
			startedAt  : $startedAt,
			finishedAt : gmdate('c'),
			durationMs : $durationMs,
			return     : $return,
			exception  : $exception,
		);

		$this->persistRun($id, $record);

		if (!$throwable instanceof \Throwable) {
			$this->state->resetFailures($id);
			$this->guard->reset($id);
			$this->activity->runSucceeded($id, $triggerType, $durationMs);
		} else {
			$failures = $this->state->incrementFailures($id);
			$this->activity->runFailed($id, $triggerType, $throwable->getMessage(), $failures);

			// Extension automations (id is `vendor/name:autoId`) are read-only —
			// no collection record to disable and no errorMailerId — so the
			// error-email + auto-disable breaker apply to file-based ones only.
			if (!str_contains($id, ':')) {
				$this->notifyError($id, $throwable);

				// Production only: trip the auto-disable breaker once failures pile
				// up so a broken handler can't fail (and email) forever.
				if ($this->guard->recordFailure($id)) {
					$this->disable($id);
					$this->activity->autoDisabled($id, $failures);
				}
			}
		}

		return $record;
	}

	private function persistRun(string $id, RunRecord $record): void
	{
		$dir = '.system/automations/' . $id . '/runs';
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

	/**
	 * Auto-disable a misbehaving automation by flipping its `enabled` flag.
	 * Silent so the resulting object.updated does not re-enter the event
	 * pipeline (an event-triggered automation must not be able to disable-loop).
	 */
	private function disable(string $id): void
	{
		try {
			$data            = $this->objectFetcher->fetchObject('automations', $id)->toArray();
			$data['enabled'] = false;
			$this->objectUpdater->updateObject('automations', $id, $data, true);
		} catch (\Throwable $e) {
			$this->logger->error("Failed to auto-disable automation '{$id}': {$e->getMessage()}");
		}
	}

	/**
	 * Email the operator when a handler throws — but only where errors are
	 * contained (Production). In dev/preview errors surface loudly instead, so
	 * we skip the mail. No-ops when the automation has no errorMailerId set.
	 */
	private function notifyError(string $id, \Throwable $e): void
	{
		if ($this->guard->shouldSurfaceErrors()) {
			return;
		}

		try {
			$data     = $this->objectFetcher->fetchObject('automations', $id)->toArray();
			$mailerId = trim((string)($data['errorMailerId'] ?? ''));
			if ($mailerId === '') {
				return;
			}

			$this->mailer->sendEmail($mailerId, [
				'automation' => $id,
				'error'      => $e->getMessage(),
			]);
		} catch (\Throwable $ex) {
			$this->logger->error("Failed to send automation error notification for '{$id}': {$ex->getMessage()}");
		}
	}
}
