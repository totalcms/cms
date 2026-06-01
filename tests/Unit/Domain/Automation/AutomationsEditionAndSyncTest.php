<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\Sync\Data\SyncableCollections;

final class AutomationsEditionAndSyncTest extends TestCase
{
	public function testAutomationsFeatureRequiresPro(): void
	{
		expect(EditionFeature::AUTOMATIONS->requiredEdition())->toBe(Edition::PRO);
		expect(EditionFeature::AUTOMATIONS->label())->toBe('Automations');
	}

	public function testAutomationsCollectionIsSyncable(): void
	{
		expect(SyncableCollections::contains('automations'))->toBeTrue();
	}
}
