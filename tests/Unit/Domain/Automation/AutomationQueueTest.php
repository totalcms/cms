<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryFilesystem;
use TotalCMS\Domain\Automation\Service\AutomationQueue;

final class AutomationQueueTest extends TestCase
{
	public function testEnqueuesAPendingRunAndDrainsItExactlyOnce(): void
	{
		$queue = new AutomationQueue(new InMemoryFilesystem());

		$runId = $queue->enqueue('daily', ['type' => 'event', 'event' => 'object.created'], ['x' => 1], ['collection' => 'orders']);
		expect($runId)->not->toBe('');

		$drained = [];
		$queue->drain(function (array $job) use (&$drained): void {
			$drained[] = $job;
		});

		expect($drained)->toHaveCount(1);
		expect($drained[0]['id'])->toBe('daily');
		expect($drained[0]['args'])->toBe(['x' => 1]);
		expect($drained[0]['event'])->toBe(['collection' => 'orders']);

		// Second drain is empty — the job file was removed.
		$second = [];
		$queue->drain(function (array $job) use (&$second): void {
			$second[] = $job;
		});
		expect($second)->toBeEmpty();
	}
}
