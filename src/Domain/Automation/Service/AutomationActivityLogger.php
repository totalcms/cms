<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Log\LoggerInterface;

/**
 * Structured, `type`-tagged activity log for automations (mirrors
 * OAuthActivityLogger). Writes to the rotating `automations-activity.log`
 * channel from day one so the future Activity Dashboard integration is a pure
 * `ActivitySource` registration — no logging retrofit.
 */
final readonly class AutomationActivityLogger
{
	public function __construct(private LoggerInterface $logger)
	{
	}

	public function runStarted(string $automationId, string $triggerType): void
	{
		$this->logger->info('Automation run started', [
			'type'          => 'run.started',
			'automation_id' => $automationId,
			'trigger'       => $triggerType,
		]);
	}

	public function runSucceeded(string $automationId, string $triggerType, int $durationMs): void
	{
		$this->logger->info('Automation run succeeded', [
			'type'          => 'run.success',
			'automation_id' => $automationId,
			'trigger'       => $triggerType,
			'duration_ms'   => $durationMs,
		]);
	}

	public function runFailed(string $automationId, string $triggerType, string $error, int $failureCount): void
	{
		$this->logger->warning('Automation run failed', [
			'type'          => 'run.failed',
			'automation_id' => $automationId,
			'trigger'       => $triggerType,
			'error'         => $error,
			'failure_count' => $failureCount,
		]);
	}

	public function autoDisabled(string $automationId, int $failureCount): void
	{
		$this->logger->warning('Automation auto-disabled after repeated failures', [
			'type'          => 'auto_disabled',
			'automation_id' => $automationId,
			'failure_count' => $failureCount,
		]);
	}
}
