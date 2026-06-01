<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryFilesystem;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;

final class AutomationStateStoreTest extends TestCase
{
	public function testRecordsAndReadsPerTriggerLastFire(): void
	{
		$store = new AutomationStateStore(new InMemoryFilesystem());

		expect($store->lastFire('daily', 't0'))->toBeNull();

		$store->recordFire('daily', 't0', '2026-05-31T01:00:00+00:00');

		expect($store->lastFire('daily', 't0'))->toBe('2026-05-31T01:00:00+00:00');
		expect($store->lastFire('daily', 't1'))->toBeNull();
	}

	public function testTracksConsecutiveFailureCountsAndResets(): void
	{
		$store = new AutomationStateStore(new InMemoryFilesystem());

		expect($store->incrementFailures('daily'))->toBe(1);
		expect($store->incrementFailures('daily'))->toBe(2);
		expect($store->failures('daily'))->toBe(2);

		$store->resetFailures('daily');
		expect($store->failures('daily'))->toBe(0);
	}
}
