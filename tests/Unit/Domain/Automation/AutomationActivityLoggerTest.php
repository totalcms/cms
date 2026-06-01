<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Service\AutomationActivityLogger;

final class AutomationActivityLoggerTest extends TestCase
{
	public function testLogsStructuredFailedRunAtWarningLevel(): void
	{
		$records = [];
		$logger  = new class($records) extends \Psr\Log\AbstractLogger {
			/** @param array<int,array<string,mixed>> $records */
			public function __construct(private array &$records)
			{
			}

			public function log($level, string|\Stringable $message, array $context = []): void
			{
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};

		(new AutomationActivityLogger($logger))->runFailed('daily', 'schedule', 'boom', 3);

		expect($records[0]['level'])->toBe('warning');
		expect($records[0]['context']['type'])->toBe('run.failed');
		expect($records[0]['context']['automation_id'])->toBe('daily');
		expect($records[0]['context']['failure_count'])->toBe(3);
	}
}
