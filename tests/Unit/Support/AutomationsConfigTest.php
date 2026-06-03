<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use TotalCMS\Support\Config;

final class AutomationsConfigTest extends TestCase
{
	public function testAutomationsConfigSectionIsReadable(): void
	{
		$config              = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->automations = ['urlPrefix' => '/automations', 'runHistoryLimit' => 100];

		expect($config->automations['urlPrefix'])->toBe('/automations');
		expect($config->automations['runHistoryLimit'])->toBe(100);
	}

	public function testAutomationsDefaultsToEmptyArray(): void
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		expect($config->automations)->toBe([]);
	}
}
