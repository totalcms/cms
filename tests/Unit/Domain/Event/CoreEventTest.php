<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Event\Data\CoreEvent;

final class CoreEventTest extends TestCase
{
	public function testAllListsEveryEventOnceWithNoDuplicates(): void
	{
		expect(CoreEvent::ALL)->toBe(array_values(array_unique(CoreEvent::ALL)));
		expect(CoreEvent::ALL)->toContain('object.created', 'extension.disabled', 'cache.cleared');
	}

	public function testOptionsAreValueLabelPairsCoveringEveryEvent(): void
	{
		$options = CoreEvent::options();

		expect($options)->toHaveCount(count(CoreEvent::ALL));
		expect($options[0])->toBe(['value' => 'object.created', 'label' => 'object.created']);

		$values = array_column($options, 'value');
		expect($values)->toBe(CoreEvent::ALL);
	}
}
