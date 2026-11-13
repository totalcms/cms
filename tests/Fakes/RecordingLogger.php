<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Psr\Log\AbstractLogger;

/**
 * Collects log records so a test can assert on them.
 */
final class RecordingLogger extends AbstractLogger
{
	/** @var list<array{level:mixed,message:string,context:array<array-key,mixed>}> */
	public array $records = [];

	public function log($level, string|\Stringable $message, array $context = []): void
	{
		$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
	}
}
